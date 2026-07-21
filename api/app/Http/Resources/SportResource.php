<?php

namespace App\Http\Resources;

use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sport
 */
class SportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'scoring' => $this->scoring,
            'participant_modes' => $this->participantModes(),
            'default_match_minutes' => (int) $this->default_match_minutes,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'stats' => $this->whenLoaded('stats', fn () => $this->stats->map(fn ($s) => [
                'id' => $s->id,
                'stat_key' => $s->stat_key,
                'label' => $s->label,
                'short' => $s->short,
                'role' => $s->role,
                'fair_play_weight' => (int) $s->fair_play_weight,
                'sort_order' => (int) $s->sort_order,
            ])),
            'positions' => $this->whenLoaded('positions', fn () => $this->positions->map(fn ($p) => [
                'id' => $p->id,
                'position_key' => $p->position_key,
                'label' => $p->label,
                'sort_order' => (int) $p->sort_order,
            ])),
        ];
    }
}
