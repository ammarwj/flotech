<?php

namespace App\Http\Resources;

use App\Models\EventCategory;
use App\Support\HybridConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventCategory
 */
class EventCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'participant_type' => $this->participant_type,
            'rubber_format' => $this->rubber_format,
            // Derived, not stored: needs the sport, which lives on the event.
            'uses_rubbers' => $this->usesRubbers(),
            // Which shape the table takes: goal | set | rubber. Same reason —
            // the client can't work it out without the sport.
            'standings_context' => $this->standingsContext(),
            'roster_size' => $this->rosterSize(),
            'tournament_format' => $this->tournament_format,
            'engine' => $this->engine(),
            'registration_fee' => (float) $this->registration_fee,
            'max_teams' => $this->max_teams,
            'bracket_config' => $this->effectiveBracketConfig(),
            'sort_order' => $this->sort_order,
            'teams_count' => $this->whenCounted('teams'),
        ];
    }

    /**
     * The stored config, said the way the standings will actually read it.
     *
     * A config written under another sport keeps keys this category cannot
     * compute — see Tiebreakers — and HybridConfig translates them on every
     * read. Sending the raw row instead would let the event page print one
     * tiebreaker order while the table ranks on a different one.
     *
     * Only keys the row already holds are replaced: an unconfigured category
     * stays null, because "belum diatur" is not the same answer as "diatur
     * dengan default", and the client fills the defaults itself.
     *
     * @return array<string, mixed>|null
     */
    private function effectiveBracketConfig(): ?array
    {
        $raw = is_array($this->bracket_config) ? $this->bracket_config : null;

        if ($raw === null) {
            return null;
        }

        $config = HybridConfig::fromCategory($this->resource);

        if (array_key_exists('tiebreakers', $raw)) {
            $raw['tiebreakers'] = $config->tiebreakers;
        }

        if (array_key_exists('points', $raw)) {
            $raw['points'] = [
                'win' => $config->pointsWin,
                'draw' => $config->pointsDraw,
                'lose' => $config->pointsLose,
            ];
        }

        return $raw;
    }
}
