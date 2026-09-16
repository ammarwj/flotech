<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EventPersonnel
 */
class EventPersonnelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'full_name' => $this->full_name,
            'kind' => $this->kind,
            // The raw column, so the editor can show an empty field as empty.
            'role_label' => $this->role_label,
            // What a card would actually print — the fallback resolved. Sent
            // alongside rather than instead, because a form that binds this one
            // would write "Wasit" into a column the organizer left blank.
            'role_display' => $this->roleLabel(),
            'photo_url' => $this->photo_url,
            'sort_order' => $this->sort_order,
        ];
    }
}
