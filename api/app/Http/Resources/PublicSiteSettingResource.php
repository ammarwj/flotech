<?php

namespace App\Http\Resources;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Footer shape: only what was actually filled in, so the footer can render a
 * platform's icon simply by iterating what it received.
 *
 * @mixin SiteSetting
 */
class PublicSiteSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'sales_email' => $this->sales_email,
            // (object) so an empty map serializes as {} and not [] — the client
            // types this as a keyed record, and an array would break the cast.
            'social_links' => (object) array_filter($this->socialLinksMap()),
        ];
    }
}
