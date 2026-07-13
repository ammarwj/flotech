<?php

namespace App\Http\Resources;

use App\Models\CertificateTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificateTemplate
 */
class CertificateTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'background_url' => $this->background_url,
            'orientation' => $this->orientation,
            'fields' => $this->fields ?? [],
            'certificates_count' => $this->whenCounted('certificates'),
            'created_at' => $this->created_at,
        ];
    }
}
