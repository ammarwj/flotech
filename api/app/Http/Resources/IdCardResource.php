<?php

namespace App\Http\Resources;

use App\Models\IdCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdCard
 */
class IdCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_name' => $this->whenLoaded('event', fn () => $this->event->name),
            'id_card_template_id' => $this->id_card_template_id,
            'card_number' => $this->card_number,
            'recipient_type' => $this->recipient_type,
            'recipient_id' => $this->recipient_id,
            'recipient_name' => $this->recipient_name,
            'role_label' => $this->role_label,
            'team_name' => $this->team_name,
            'recipient_email' => $this->recipient_email,
            // The PNG is served through the API (auth + tenant), never as a raw
            // bucket URL — the key itself stays server-side, same as
            // CertificateResource::has_pdf.
            'has_png' => $this->png_key !== null,
            'verify_url' => $this->verifyUrl(),
            'issued_at' => $this->issued_at,
            'sent_at' => $this->sent_at,
        ];
    }
}
