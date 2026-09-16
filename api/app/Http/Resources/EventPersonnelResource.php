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
            // Raw, for the same reason as role_label below: the editor binds to
            // what was typed, and an empty field has to stay empty.
            'email' => $this->email,
            // Whether a login exists for that address, which is a different
            // question — an address can be typed, saved, and still belong to
            // nobody if the row predates provisioning. `user_id` itself is
            // deliberately not published: the organizer has no use for another
            // account's id, and every route that authorizes reads it server-side.
            'has_account' => $this->user_id !== null,
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
