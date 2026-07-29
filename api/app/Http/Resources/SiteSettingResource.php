<?php

namespace App\Http\Resources;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape: every platform is present, `null` included, so the settings form
 * binds to a stable set of inputs. The public counterpart strips the blanks.
 *
 * @mixin SiteSetting
 */
class SiteSettingResource extends JsonResource
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
            'social_links' => $this->socialLinksMap(),
            // The platform's own payout account, behind `superadmin`. Its public
            // counterpart deliberately omits these: the footer has no business
            // with them, and a full account number belongs only in a manual
            // payment flow — see PlatformBankAccountResource.
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,
        ];
    }
}
