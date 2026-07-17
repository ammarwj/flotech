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
            'category_id' => $this->category_id,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'status' => $this->status,
            'group_name' => $this->group_name,
            'seed_pot' => $this->seed_pot,
            'registered_at' => $this->registered_at,
            'approved_at' => $this->approved_at,
            'manager_user_id' => $this->manager_user_id,
            'payment_status' => $this->payment_status,
            'payment_amount' => (float) $this->payment_amount,
            'platform_fee' => (float) $this->platform_fee,
            'paid_at' => $this->paid_at,
            'midtrans_token' => $this->midtrans_token,
            'payment_method' => $this->payment_method,
            // Derived, not stored — see HasManualPayment for why there is no
            // `awaiting_verification` status value.
            'awaiting_verification' => $this->isAwaitingVerification(),
            'payment_proof_url' => $this->payment_proof_url,
            'payment_proof_uploaded_at' => $this->payment_proof_uploaded_at,
            'payment_deadline_at' => $this->payment_deadline_at,
            'rejected_reason' => $this->rejected_reason,
            'verified_at' => $this->verified_at,
            'event' => new EventResource($this->whenLoaded('event')),
            'category' => new EventCategoryResource($this->whenLoaded('category')),
            'players' => $this->whenLoaded('players', fn () => $this->players->map(fn ($p) => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'jersey_number' => $p->jersey_number,
                'position' => $p->position,
                'photo_url' => $p->photo_url,
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
