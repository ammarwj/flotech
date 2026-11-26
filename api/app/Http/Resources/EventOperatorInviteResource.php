<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EventOperatorInvite
 */
class EventOperatorInviteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'email' => $this->email,
            'can_ticketing' => $this->can_ticketing,
            'can_scheduling' => $this->can_scheduling,
            'can_registration' => $this->can_registration,
            'can_documents' => $this->can_documents,
            'status' => $this->status,
            'is_expired' => $this->isExpired(),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'invited_by' => $this->invitedBy?->full_name,
            'created_at' => $this->created_at,
        ];
    }
}
