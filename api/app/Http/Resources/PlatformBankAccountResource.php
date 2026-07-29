<?php

namespace App\Http\Resources;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * flo-event's own account, as an organizer paying for a plan needs to see it:
 * full number, because they have to type it into their banking app.
 *
 * The shape is identical to PublicBankAccountResource on purpose — the web
 * client reuses one type and one transfer panel for both — but the class is
 * separate because the two answer different questions. That one publishes an
 * *organizer's* account to a buyer; this one publishes *ours* to an organizer.
 * Keeping them apart means either can be tightened without touching the other.
 *
 * Only ever return this from the plan payment flow. The public footer resource
 * deliberately omits these columns.
 *
 * @mixin SiteSetting
 */
class PlatformBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,
        ];
    }
}
