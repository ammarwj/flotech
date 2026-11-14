<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Services\DomainService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The validation rules for a domain add-on, spelled once.
 *
 * Three doors accept one: plan checkout, add-on-only checkout, and the price
 * preview. Three copies is how one door starts accepting a name another refuses
 * — the same reason `PayoutDestinationRequest` is abstract rather than copied,
 * and the same reason the preview endpoint shares `DiscountCodeService::resolve`
 * with the till.
 *
 * What is NOT here: anything DomainService already owns. Reserved platform
 * hostnames, draft events, and whether the hostname is shaped like one are all
 * checked in assign()/assignSubdomain(), which is the single write path — and
 * restating them would give each rule two enforcement points to keep in step.
 * What IS here is only what has to be refused *before money moves*: a kind we
 * do not sell, a TLD we cannot register, and a name already taken. Paying for a
 * subdomain somebody else holds is the one failure the organizer cannot undo
 * themselves.
 */
class DomainAddonRules
{
    /**
     * @param  bool  $required  true at the add-on-only door (a purchase of
     *                          nothing is not a purchase), false alongside a
     *                          plan, where the add-on is optional.
     * @return array<string, mixed>
     */
    public static function rules(bool $required = false): array
    {
        return [
            'domain_kind' => [
                $required ? 'required' : 'nullable',
                Rule::in(DomainAddons::KINDS),
            ],
            // Required for `own` and `subdomain` — there is nothing to configure
            // without a name — and optional for `register`, deliberately: an
            // organizer may buy "a .id domain" and tell us the name later, or
            // leave the choice to us. `exclude_if` keeps the key out of the
            // validated payload entirely when no add-on was asked for, so a
            // stray empty string cannot reach quoteAddon() as a kind-less name.
            'domain_name' => [
                'exclude_if:domain_kind,null',
                Rule::requiredIf(fn () => in_array(
                    request()->input('domain_kind'),
                    [DomainAddons::KIND_OWN, DomainAddons::KIND_SUBDOMAIN],
                    true,
                )),
                'nullable',
                'string',
                'max:253',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'domain_kind.required' => 'Pilih jenis domain.',
            'domain_kind.in' => 'Jenis domain tidak dikenal.',
            'domain_name.required' => 'Masukkan nama domainnya.',
            'domain_name.max' => 'Nama domain terlalu panjang.',
        ];
    }

    /**
     * The cross-row checks, as an `after` hook on a validator.
     *
     * Separate from rules() because each needs to look at other rows or at the
     * catalogue, which a rule string cannot — the same split
     * `UpdateEventDomainRequest` makes, and for the same reason: a closure rule
     * that silently matched nothing would be a refusal that never fires.
     *
     * `$kind` is for the one door that does not carry the field: a super admin
     * fulfilling a `register` order is typing a name against a kind the order
     * already settled, and reading it off the payload there would find nothing
     * and skip every check. Passed explicitly rather than defaulted to
     * `register`, so a future door cannot inherit the wrong one by omission.
     */
    public static function check(Validator $validator, ?Event $excluding = null, ?string $kind = null): void
    {
        $validator->after(function (Validator $v) use ($excluding, $kind) {
            $data = $v->getData();
            $kind ??= $data['domain_kind'] ?? null;

            if ($kind === null) {
                return;
            }

            $raw = trim((string) ($data['domain_name'] ?? ''));

            // A name is optional for `register` — the organizer may buy "a .id
            // domain" and name it later — but an extension we cannot sell is
            // not: DomainAddons::registrationPrice() returns 0 for a TLD we do
            // not resell, which would bill the registration as free.
            if (
                $kind === DomainAddons::KIND_REGISTER
                && $raw !== ''
                && ! in_array(DomainAddons::tldOf($raw), DomainAddons::tlds(), true)
            ) {
                $v->errors()->add('domain_name', sprintf(
                    'flo-event baru menjual ekstensi %s.',
                    implode(' dan ', array_map(fn ($t) => '.'.$t, DomainAddons::tlds())),
                ));

                return;
            }

            if ($raw === '') {
                return;
            }

            $host = self::hostFor($kind, $raw);

            if ($kind === DomainAddons::KIND_SUBDOMAIN) {
                $label = DomainNames::normalizeLabel($raw);

                if (! DomainNames::isValidLabel($label)) {
                    $v->errors()->add('domain_name', 'Nama subdomain tidak valid. Pakai 3–40 huruf kecil, angka, atau tanda hubung.');

                    return;
                }

                if (DomainNames::isReservedLabel($label)) {
                    $v->errors()->add('domain_name', 'Nama itu dipakai platform dan tidak bisa dipesan.');

                    return;
                }
            }

            if (! self::isAvailable($host, $excluding)) {
                $v->errors()->add('domain_name', 'Nama itu sudah dipakai event lain.');
            }
        });
    }

    /**
     * Is this hostname free?
     *
     * Checks `custom_domain` (where every live hostname sits, issued or brought)
     * and any outstanding order that has already asked for it. The second half
     * matters: two organizers can otherwise both pay for `liga-a.floevent.id`
     * and only one of them can be given it — and the loser has paid.
     */
    public static function isAvailable(string $host, ?Event $excluding = null): bool
    {
        $taken = Event::where('custom_domain', $host)
            ->when($excluding, fn ($q) => $q->where('id', '!=', $excluding->id))
            ->exists();

        if ($taken) {
            return false;
        }

        return ! EventPlanOrder::where('domain_name', $host)
            // A cancelled order never got the name, and a fulfilled one that
            // was later released gave it back — neither holds a claim. What
            // does: an unpaid bill someone may still settle, and a paid one
            // waiting on registration.
            ->whereIn('status', ['past_due', 'paid'])
            ->when($excluding, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('domain_event_id')->orWhere('domain_event_id', '!=', $excluding->id),
            ))
            ->exists();
    }

    /**
     * The hostname a request will be stored as.
     *
     * The one place the label-to-hostname composition happens for validation,
     * matching what EventPlanOrderService::addonHost() writes — if these two
     * disagreed, a name could validate as free and be stored as a different
     * string entirely.
     */
    public static function hostFor(string $kind, ?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        return $kind === DomainAddons::KIND_SUBDOMAIN
            ? DomainNames::hostFor(DomainNames::normalizeLabel($raw))
            : DomainService::normalize($raw);
    }
}
