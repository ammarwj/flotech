<?php

namespace App\Http\Resources;

use App\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameMatch
 */
class MatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'round' => $this->round,
            'group_name' => $this->group_name,
            'bracket' => $this->bracket,
            'order' => $this->order,
            'leg' => $this->leg,
            'home_team' => $this->teamSummary($this->whenLoaded('homeTeam')),
            'away_team' => $this->teamSummary($this->whenLoaded('awayTeam')),
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'home_penalty' => $this->home_penalty,
            'away_penalty' => $this->away_penalty,
            'sets' => $this->sets,
            'status' => $this->status,
            'confirmed' => $this->confirmed_at !== null,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'venue' => $this->venue,
        ];
    }

    /**
     * @param  mixed  $team
     * @return array<string, mixed>|null
     */
    protected function teamSummary($team): ?array
    {
        if (! $team) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'city' => $team->city,
            'logo_url' => $team->logo_url,
        ];
    }
}
