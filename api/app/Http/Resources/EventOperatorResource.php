<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EventOperator
 */
class EventOperatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user' => [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'email' => $this->user->email,
            ],
            'can_ticketing' => $this->can_ticketing,
            'can_scheduling' => $this->can_scheduling,
            'can_registration' => $this->can_registration,
            'can_documents' => $this->can_documents,
            'invited_by' => $this->invitedBy?->full_name,
            'created_at' => $this->created_at,
        ];
    }
}
