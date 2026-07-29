<?php

namespace App\Support;

use App\Models\EventCategory;
use App\Services\Catalog;

/**
 * When a card turns into a ban, resolved for one category.
 *
 * Three layers, shallow-merged per key: the fallbacks below, then the sport's
 * defaults (`sports.discipline_config`, editable at /admin/sports), then the
 * event's own override (`events.rules_config['discipline']`). An organizer
 * running a five-match tournament sets the threshold to 2; nobody else has to
 * think about it.
 *
 * `enabled` is not a setting. It answers "does this sport book players at all?"
 * and is read off the stat catalog: a sport with a 'yellow' or 'red' role has
 * cards, one without doesn't. That test is deliberately not `isRacketSport` —
 * volleyball and basketball field squads and show player stats, yet have no card
 * column, so a coincidental test would switch this feature on for two sports it
 * can never fire in.
 */
final class DisciplineRules
{
    /**
     * The rulebook a sport gets when nobody has said otherwise: three yellows
     * across the tournament sit a player down for one match, two in a single
     * match are a sending-off, and a red does it on its own.
     */
    public const DEFAULTS = [
        'yellow_threshold' => 3,
        'yellow_ban_matches' => 1,
        'red_ban_matches' => 1,
        'yellows_per_expulsion' => 2,
        'expulsion_ban_matches' => 1,
        'reset_yellow_on_knockout' => false,
    ];

    private function __construct(
        /** Does this sport track cards at all? Everything else is moot when false. */
        public readonly bool $enabled,
        public readonly ?string $yellowKey,
        public readonly ?string $redKey,
        /** Yellows that accumulate into a ban. Never 0 — that would not terminate. */
        public readonly int $yellowThreshold,
        public readonly int $yellowBanMatches,
        public readonly int $redBanMatches,
        /**
         * Yellows inside one match that amount to a sending-off. Unlike the
         * threshold above this one *may* be 0, and 0 means "switch the rule
         * off" — it is read by a plain comparison, not a loop, so there is
         * nothing to hang.
         */
        public readonly int $yellowsPerExpulsion,
        public readonly int $expulsionBanMatches,
        public readonly bool $resetYellowOnKnockout,
    ) {}

    public static function forCategory(EventCategory $category): self
    {
        $overrides = $category->event?->rules_config['discipline'] ?? null;

        return self::forSport($category->sport_type, is_array($overrides) ? $overrides : []);
    }

    /**
     * @param  array<string, mixed>  $eventOverrides  contents of events.rules_config['discipline']
     */
    public static function forSport(?string $slug, array $eventOverrides = []): self
    {
        $merged = [
            ...self::DEFAULTS,
            ...self::clean(Catalog::disciplineConfig($slug)),
            ...self::clean($eventOverrides),
        ];

        $yellowKey = Catalog::statKeyForRole($slug, 'yellow');
        $redKey = Catalog::statKeyForRole($slug, 'red');

        return new self(
            enabled: $yellowKey !== null || $redKey !== null,
            yellowKey: $yellowKey,
            redKey: $redKey,
            // Guarded rather than trusted: the accumulation loop runs `while
            // (running >= threshold)`, so a 0 that slipped past validation — or
            // arrived in a row written before the rule existed — would hang the
            // request rather than produce a wrong number.
            yellowThreshold: max(1, (int) $merged['yellow_threshold']),
            yellowBanMatches: max(0, (int) $merged['yellow_ban_matches']),
            redBanMatches: max(0, (int) $merged['red_ban_matches']),
            yellowsPerExpulsion: max(0, (int) $merged['yellows_per_expulsion']),
            expulsionBanMatches: max(0, (int) $merged['expulsion_ban_matches']),
            resetYellowOnKnockout: (bool) $merged['reset_yellow_on_knockout'],
        );
    }

    /**
     * The rules as the client reads them, or null when the sport has no cards.
     *
     * The stat keys travel along so the UI can name a card from the sport's own
     * label ("Kartu Kuning") instead of hardcoding one.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        return [
            'yellow_threshold' => $this->yellowThreshold,
            'yellow_ban_matches' => $this->yellowBanMatches,
            'red_ban_matches' => $this->redBanMatches,
            'yellows_per_expulsion' => $this->yellowsPerExpulsion,
            'expulsion_ban_matches' => $this->expulsionBanMatches,
            'reset_yellow_on_knockout' => $this->resetYellowOnKnockout,
            'yellow_stat_key' => $this->yellowKey,
            'red_stat_key' => $this->redKey,
        ];
    }

    /**
     * Validation rules for one rulebook, shared by the sport master and the two
     * event requests so the three can't drift apart.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(string $prefix): array
    {
        return [
            $prefix.'yellow_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            $prefix.'yellow_ban_matches' => ['nullable', 'integer', 'min:0', 'max:10'],
            $prefix.'red_ban_matches' => ['nullable', 'integer', 'min:0', 'max:10'],
            // min:0 rather than min:1 — 0 is how an organizer switches the
            // sending-off rule off for their tournament.
            $prefix.'yellows_per_expulsion' => ['nullable', 'integer', 'min:0', 'max:10'],
            $prefix.'expulsion_ban_matches' => ['nullable', 'integer', 'min:0', 'max:10'],
            $prefix.'reset_yellow_on_knockout' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Drop nulls and anything we don't recognise before merging.
     *
     * The nulls matter: a cleared field in the event form means "follow the
     * sport default", and it arrives as null. Merged as-is it would overwrite
     * the layer below with null instead of deferring to it.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function clean(array $raw): array
    {
        return array_filter(
            array_intersect_key($raw, self::DEFAULTS),
            fn ($value) => $value !== null,
        );
    }
}
