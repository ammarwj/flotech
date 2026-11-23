<?php

namespace App\Support;

/**
 * What the four fields the PLATFORM always asks for are called on this event.
 *
 * A team registration always carries a logo, a name, a contact name and a
 * contact phone — they are columns on `teams`, not rows in the organizer's
 * schema, so they cannot be renamed the way a custom field can be deleted and
 * retyped. But what they are *called* is not the platform's business: a
 * badminton club list wants "Nama klub", a school tournament wants "Nama
 * sekolah", an instansi wants "Nama instansi". The column keeps its name; the
 * question above it does not have to.
 *
 * **Code is the catalogue AND the default; the DB stores only overrides.**
 * Same shape as LandingMetrics/landing_stat_settings and the reason is the
 * same: an event that never touched this must render exactly what this file
 * says, so improving the wording in a deploy reaches production without a
 * migration. clean() therefore DROPS a value that equals the default — a row
 * that spells out today's default freezes it, and tomorrow's deploy would
 * never reach that event again.
 *
 * Deliberately NOT feature_definitions and deliberately NOT its own table:
 * feature_definitions tells plans apart, and this is a setting the organizer
 * types per event — the same call manual_transfer and the discipline rules
 * make. It rides inside `events.registration_form` because that column already
 * IS "what this event's registration form looks like", and a second column
 * would be a second place to look for one answer.
 *
 * The four roster labels (player name, jersey, position, official role) are
 * deliberately out of scope. Those are read back by five things that have
 * nothing to do with wording — syncDerivedName(), the rubber lineup picker,
 * player_match_stats, certificates, the import template's per-player columns —
 * and renaming the question there invites renaming the concept.
 */
final class CoreFieldLabels
{
    /**
     * Key => the label an event that never touched this gets.
     *
     * The keys are the `teams` columns they sit above, minus `logo_url`'s
     * suffix: they are stable identifiers stored in JSON, so they are spelled
     * once, here, and never derived from a label (see RegistrationForm's
     * keyFrom for why a derived key is a key that moves).
     */
    public const DEFAULTS = [
        'team_logo' => 'Logo tim',
        'team_name' => 'Nama tim',
        'contact_name' => 'Nama kontak',
        'contact_phone' => 'No. HP kontak',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * The stored overrides: known keys only, trimmed, and **only those that
     * actually differ from the default**.
     *
     * The second half is the half that is easy to drop and impossible to
     * notice: with it, an organizer who opens the page and saves without
     * typing anything writes four rows that pin today's wording into their
     * event forever. Compare a same-value save (nothing stored) against a real
     * rename (stored) in one test — asserting "it saved" passes either way.
     *
     * @param  mixed  $raw
     * @return array<string, string>
     */
    public static function clean($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $raw[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            // Empty means "back to the default", the same reading a cleared
            // DisciplineRules field gets — not a field with no question above
            // it, which is what storing '' would render.
            if ($value === '' || $value === $default) {
                continue;
            }

            $clean[$key] = mb_substr($value, 0, 60);
        }

        return $clean;
    }

    /**
     * What one field is called here. Unknown keys answer with themselves so a
     * caller can never print nothing above an input.
     *
     * @param  array<string, string>  $labels
     */
    public static function label(array $labels, string $key): string
    {
        $value = $labels[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return self::DEFAULTS[$key] ?? $key;
    }

    /**
     * Every key with its effective label — what the API publishes, so the
     * client never merges defaults of its own.
     *
     * The client mirrors this catalogue (web/lib/registration-form.ts) for the
     * builder's placeholders, but what it RENDERS above an input is this map:
     * two readers merging the same two layers is the effective_payment_method
     * bug in another shape.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    public static function all(array $labels): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $_) {
            $out[$key] = self::label($labels, $key);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(string $prefix = 'labels'): array
    {
        $rules = [$prefix => ['nullable', 'array']];

        foreach (self::keys() as $key) {
            $rules["{$prefix}.{$key}"] = ['nullable', 'string', 'max:60'];
        }

        return $rules;
    }
}
