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
            // `sales_email` is deliberately absent. It fed the "Hubungi Sales"
            // CTA on the Professional card, which went when the catalogue became
            // self-serve, and no enterprise tier was ever added to replace it —
            // so nothing on any public page renders it. Shipping an internal
            // address to every visitor for a button that no longer exists is a
            // leak with no upside. It stays editable at /admin/site-settings
            // and stays in SiteSettingResource, ready if a sales motion returns.
            // (object) so an empty map serializes as {} and not [] — the client
            // types this as a keyed record, and an array would break the cast.
            'social_links' => (object) array_filter($this->socialLinksMap()),
        ];
    }
}
