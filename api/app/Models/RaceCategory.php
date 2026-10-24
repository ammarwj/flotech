<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category a fun run sells entries in: Mahasiswa, Umum, 5K, 10K.
 *
 * Its own table rather than `event_categories` — see the migration. Nothing
 * here knows about formats, brackets, rosters or standings, which is the point:
 * the nine readers of a competition category never have to grow a branch for a
 * sport that holds no matches.
 *
 * There is deliberately no `price` on this model. What an entry costs depends on
 * when it is bought, and RacePricing is the only thing that answers it.
 */
class RaceCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'slug',
        'description',
        'email_domains',
        'quota',
        'taken',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'email_domains' => 'array',
            'quota' => 'integer',
            'taken' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Normalise one domain as the organizer typed it.
     *
     * Spelled once and read from BOTH sides — prepareForValidation() on the way
     * in, acceptsEmail() on the way out — the pattern DiscountCode::normalize()
     * set. A write-side-only normaliser would store `ub.ac.id` and then compare
     * a runner's `UB.ac.id` against it, and the refusal would be invisible:
     * the stored value looks right on every screen.
     *
     * Accepts `@ub.ac.id`, ` ub.ac.id `, `https://ub.ac.id/x` and `ub.ac.id.`.
     * Returns null for anything that normalises to nothing, so the caller drops
     * it rather than storing an empty string that admits no one.
     */
    public static function normalizeDomain(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        // Everything from the first slash on: a URL pasted whole
        // ("https://ub.ac.id/mahasiswa") keeps only its host, and a bare
        // trailing slash stops failing the pattern for a reason the organizer
        // cannot see on the chip.
        $value = (string) strstr($value.'/', '/', true);
        // Everything after the last @: a whole address pasted into the field
        // ("nama@ub.ac.id") is the mistake this makes harmless.
        $parts = explode('@', $value);
        $value = (string) end($parts);
        $value = trim($value, " \t\n\r\0\x0B.");
        $value = strtolower($value);

        return $value === '' ? null : $value;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The domains allowed to enter, always an array — `[]` means open to
     * anyone, which is what a null column says (see the migration).
     *
     * @return list<string>
     */
    public function emailDomains(): array
    {
        return array_values($this->email_domains ?? []);
    }

    public function requiresEmailDomain(): bool
    {
        return $this->emailDomains() !== [];
    }

    /**
     * Whether this address may enter — the ONLY matcher on the server.
     *
     * **Exact, never by suffix.** `str_ends_with($email, $domain)` would let
     * `ub.ac.id` admit `student.ub.ac.id` (which may be wanted) and also
     * `notub.ac.id` and `fakeub.ac.id` (which is the hole), so a subdomain is
     * its own entry the organizer types. The form says so under the field.
     *
     * A category with no domains accepts everything, so this is safe to call
     * unconditionally.
     */
    public function acceptsEmail(string $email): bool
    {
        $domains = $this->emailDomains();

        if ($domains === []) {
            return true;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = self::normalizeDomain(substr($email, $at + 1));

        return $domain !== null && in_array($domain, $domains, true);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class, 'race_category_id');
    }

    /** This category's price for each period of its event, keyed by nothing — read via RacePricing. */
    public function prices(): HasMany
    {
        return $this->hasMany(RaceCategoryPrice::class, 'race_category_id');
    }

    /**
     * Places still free, or null when the quota is unlimited.
     *
     * Mirrors TicketCategory::remaining(). `taken` is the reservation, not the
     * paid count: an unpaid entry really is holding a place until it expires,
     * which is what race-entries:expire-manual is for.
     */
    public function remaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->taken);
    }

    /**
     * Whether this category can be entered at all right now.
     *
     * Only the half that belongs to the category: active, and with room. The
     * other half — "is any period in force, and does this category have a price
     * during it" — is RacePricing's, because it needs the event's windows.
     * Folding them into one boolean here would make this model the second
     * reader of the pricing rule.
     */
    public function isOpen(): bool
    {
        return $this->is_active && ($this->remaining() === null || $this->remaining() > 0);
    }
}
