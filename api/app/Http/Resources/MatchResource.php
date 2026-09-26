<?php

namespace App\Http\Resources;

use App\Models\GameMatch;
use App\Services\MatchClockService;
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
            'category_id' => $this->category_id,
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
            // A squad tie carries its partai instead of a run of sets; home/away
            // score above is how many of them each side won. Each partai is
            // handed its parent back so it can name its lineup from the rosters
            // already loaded here, rather than querying for them itself.
            'rubbers' => $this->whenLoaded('rubbers', fn () => RubberResource::collection(
                $this->rubbers->each(fn ($rubber) => $rubber->setRelation('match', $this->resource)),
            )),
            'status' => $this->status,
            'confirmed' => $this->confirmed_at !== null,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'venue' => $this->venue,
            // Babak & jam, atau null untuk cabang set dan tie beregu yang tidak
            // punya babak. Diturunkan tiap pembacaan, tidak pernah disimpan —
            // lihat MatchClockService. Di-resolve lewat `app()` karena
            // JsonResource tidak bisa menerima constructor injection.
            'clock' => app(MatchClockService::class)->snapshot($this->resource),
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
            'logo_url' => $team->logo_url,
        ];
    }
}
