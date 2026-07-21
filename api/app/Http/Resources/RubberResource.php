<?php

namespace App\Http\Resources;

use App\Models\MatchRubber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MatchRubber
 */
class RubberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'order' => $this->order,
            'label' => $this->label,
            'type' => $this->type,
            'home_player_ids' => $this->home_player_ids ?? [],
            'away_player_ids' => $this->away_player_ids ?? [],
            // Names too, so a spectator's match card doesn't have to fetch two
            // rosters to read "Dimas/Ammar vs Ucang/Devan". Present only where
            // the rosters were eager-loaded alongside the partai.
            'home_players' => $this->lineup($this->home_player_ids, 'homeTeam'),
            'away_players' => $this->lineup($this->away_player_ids, 'awayTeam'),
            'sets' => $this->sets,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'status' => $this->status,
        ];
    }

    /**
     * @param  array<int, string>|null  $ids
     * @return array<int, string>|null
     */
    protected function lineup(?array $ids, string $relation): ?array
    {
        // MatchResource hands each partai its parent back for exactly this.
        if (! $ids || ! $this->resource->relationLoaded('match')) {
            return null;
        }

        $team = $this->match->relationLoaded($relation) ? $this->match->getRelation($relation) : null;

        if (! $team || ! $team->relationLoaded('players')) {
            return null;
        }

        return $team->players
            ->whereIn('id', $ids)
            // Roster order is meaningless here; the lineup's own order is.
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))
            ->pluck('full_name')
            ->values()
            ->all();
    }
}
