<?php

namespace App\Support;

use App\Services\PlatformSettings;

/**
 * The domain add-on catalogue: which kinds exist, which TLDs we resell, and what
 * each one costs.
 *
 * Three kinds, one add-on. They differ only in where the hostname comes from —
 * the organizer already owns it, we buy it for them, or we hand them a label
 * under `floevent.id` — and that is the whole reason they are three values of
 * one column rather than three features. Everything downstream of the purchase
 * (the nginx vhost, the certificate, the white-label page) is identical.
 *
 * `DomainService` is still the only thing that may move a domain forward; this
 * class only answers "what is being bought, and for how much". The split
 * matters because the price has to be quotable *before* the order row exists —
 * same reason `PaymentRails::platformDestination()` is planless.
 *
 * Every price is a super-admin setting read through PlatformSettings, never a
 * literal here: the configuration fee and both registration prices are
 * commercial numbers, and config/domains.php holds only their defaults.
 */
class DomainAddons
{
    /** Organizer already holds the domain; we only configure and certify it. */
    public const KIND_OWN = 'own';

    /** We register the domain on their behalf, then configure it. */
    public const KIND_REGISTER = 'register';

    /** A label under the platform's own domain, e.g. `liga-a.floevent.id`. */
    public const KIND_SUBDOMAIN = 'subdomain';

    /** @var list<string> */
    public const KINDS = [self::KIND_OWN, self::KIND_REGISTER, self::KIND_SUBDOMAIN];

    /**
     * TLD => the PlatformSettings key holding its yearly registration price.
     *
     * Keyed by the setting rather than the price so adding a TLD is one entry
     * here plus one definition in PlatformSettings — the same two-sided rule as
     * `plan_features`/`feature_definitions`, and for the same reason: a TLD with
     * no setting would quote as free.
     *
     * @var array<string, string>
     */
    public const REGISTRABLE_TLDS = [
        'com' => 'domain_register_com_amount',
        'id' => 'domain_register_id_amount',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /** @return list<string> */
    public static function tlds(): array
    {
        return array_keys(self::REGISTRABLE_TLDS);
    }

    /**
     * The configuration fee, charged on all three kinds.
     *
     * It is the work we actually do every time — DNS check, certificate, vhost —
     * and it does not get cheaper because the organizer brought their own name.
     */
    public static function setupFee(): float
    {
        return (float) PlatformSettings::get('domain_setup_fee_amount');
    }

    /**
     * What we charge to register `$tld` for a year. Zero for a TLD we do not
     * resell, which `quote()` refuses before it can ever be billed.
     */
    public static function registrationPrice(string $tld): float
    {
        $key = self::REGISTRABLE_TLDS[mb_strtolower($tld)] ?? null;

        return $key === null ? 0.0 : (float) PlatformSettings::get($key);
    }

    /**
     * The TLD of a hostname, lowercased and without its dot — `''` when the
     * name has no dot at all, which is not a hostname and is refused upstream.
     */
    public static function tldOf(string $domain): string
    {
        $parts = explode('.', mb_strtolower(trim($domain)));

        return count($parts) < 2 ? '' : (string) end($parts);
    }

    /**
     * Price this add-on, split into the two lines a document has to print.
     *
     * `register` is the only kind with a second line; the other two are the
     * configuration fee alone. Returned as a breakdown rather than one total
     * because the invoice shows both and the order snapshots both — collapsing
     * them would make the registration price unrecoverable the day the setting
     * changes, the same reason `withdrawals` snapshots its own fee.
     *
     * @return array{setup: float, registration: float, total: float}
     */
    public static function quote(string $kind, ?string $domain = null): array
    {
        $setup = self::setupFee();

        $registration = $kind === self::KIND_REGISTER
            ? self::registrationPrice(self::tldOf((string) $domain))
            : 0.0;

        return [
            'setup' => $setup,
            'registration' => $registration,
            'total' => round($setup + $registration, 2),
        ];
    }

    /**
     * The catalogue as the client renders it: every kind with its price, plus
     * the resellable TLDs and what each costs.
     *
     * One endpoint feeds both the checkout dialog and the buy-later page, so
     * neither can price an add-on the till would refuse — the same reason
     * `plan-orders/discount-preview` exists.
     *
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        return [
            'setup_fee' => self::setupFee(),
            'kinds' => self::KINDS,
            'tlds' => array_map(fn (string $tld) => [
                'tld' => $tld,
                'price' => self::registrationPrice($tld),
                // What the organizer actually pays for this TLD: registration
                // plus the configuration fee. Sent rather than left to the
                // client to add up, so the number on the card and the number at
                // the till come from one place.
                'total' => round(self::registrationPrice($tld) + self::setupFee(), 2),
            ], self::tlds()),
            'platform_domain' => DomainNames::platformDomain(),
        ];
    }
}
