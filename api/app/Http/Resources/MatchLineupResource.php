<?php

namespace App\Http\Resources;

use App\Models\MatchLineup;
use App\Services\Catalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One team's sheet, as the manager's editor, the referee's review screen and the
 * printed sheet all read it.
 *
 * Player and official details are flattened in from the roster row rather than
 * being stored on the sheet — a shirt number that changes must change everywhere,
 * and the sheet is the copy nobody would go back to fix.
 *
 * @mixin MatchLineup
 */
class MatchLineupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sport = $this->team?->event?->sport_type;

        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'team_id' => $this->team_id,
            'team_name' => $this->team?->name,
            'status' => $this->status,
            // The one piece of copy every reader would otherwise invent for
            // itself; see EventPersonnel::KIND_LABELS for the same split.
            'status_display' => $this->statusLabel(),
            // What the manager may still do, answered by the server rather than
            // re-derived from `status` on three screens.
            'editable' => $this->isEditable(),
            'note' => $this->note,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->full_name),
            'players' => $this->whenLoaded('players', fn () => $this->players->map(fn ($row) => [
                'id' => $row->id,
                'player_id' => $row->player_id,
                'role' => $row->role,
                'sort_order' => $row->sort_order,
                'full_name' => $row->player?->full_name,
                'jersey_number' => $row->player?->jersey_number,
                'position' => $row->player?->position,
            ])->values()),
            'officials' => $this->whenLoaded('officials', fn () => $this->officials->map(fn ($row) => [
                'id' => $row->id,
                'team_official_id' => $row->team_official_id,
                'sort_order' => $row->sort_order,
                'full_name' => $row->official?->full_name,
                'role' => $row->official?->role,
                // Resolved from the sport's catalogue, never hardcoded — an admin
                // may rename a role and every screen has to follow.
                'role_display' => Catalog::officialRoleLabel($sport, $row->official?->role),
            ])->values()),
        ];
    }
}
