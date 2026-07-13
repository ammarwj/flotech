<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Certificate
 */
class CertificateResource extends JsonResource
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
            'certificate_template_id' => $this->certificate_template_id,
            'certificate_number' => $this->certificate_number,
            'recipient_type' => $this->recipient_type,
            'recipient_id' => $this->recipient_id,
            'recipient_name' => $this->recipient_name,
            'team_name' => $this->team_name,
            'recipient_email' => $this->recipient_email,
            'award_title' => $this->award_title,
            // The PDF is served through the API (auth + tenant), never as a raw
            // bucket URL — the key itself stays server-side.
            'has_pdf' => $this->pdf_key !== null,
            'issued_at' => $this->issued_at,
            'sent_at' => $this->sent_at,
        ];
    }
}
