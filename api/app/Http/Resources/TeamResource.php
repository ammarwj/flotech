<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
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
            'logo_url' => $this->logo_url,
            'city' => $this->city,
            'jersey_color' => $this->jersey_color,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'status' => $this->status,
            'group_name' => $this->group_name,
            'registered_at' => $this->registered_at,
            'approved_at' => $this->approved_at,
            'manager_user_id' => $this->manager_user_id,
            'event' => new EventResource($this->whenLoaded('event')),
            'players' => $this->whenLoaded('players', fn () => $this->players->map(fn ($p) => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'jersey_number' => $p->jersey_number,
                'position' => $p->position,
            ])),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id' => $d->id,
                'document_type' => $d->document_type,
                'file_name' => $d->file_name,
                'file_url' => $d->file_url,
            ])),
        ];
    }
}
