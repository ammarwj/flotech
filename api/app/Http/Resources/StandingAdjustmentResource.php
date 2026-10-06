<?php

namespace App\Http\Resources;

use App\Models\StandingAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StandingAdjustment
 */
class StandingAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            // The team's *name*, not just its id: this list is read beside a
            // table the reader is already looking at, and an id resolves to
            // nothing on screen.
            'team_name' => $this->whenLoaded('team', fn () => $this->team->name),
            'points' => $this->points,
            'reason' => $this->reason,
            // Likewise a name, because the question a ledger row answers is
            // "who typed this" and nothing on the client can resolve a user id.
            // Null when the account is gone — the entry outlives it by design.
            'created_by_name' => $this->whenLoaded('author', fn () => $this->author?->full_name),
            'created_at' => $this->created_at,
        ];
    }
}
