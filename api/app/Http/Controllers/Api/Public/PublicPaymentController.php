<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\PaymentFeeCalculator;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fee preview for the channel picker. Global, not event-keyed — the gateway
 * fee is the same everywhere, so one endpoint serves all checkout flows.
 *
 * `audience` is the one thing that does differ: the platform's own margin is
 * set separately for each of the three flows — a participant buying tickets, a
 * participant paying a team's registration fee, and an organizer buying a plan
 * from us. `units` is the second: the ticket margin is per seat, so a basket of
 * three carries three of them, while the other two are bought once. It is a
 * preview of public pricing either way — none of the rates is a secret, and the
 * number that gets charged is recomputed server-side at order time.
 */
class PublicPaymentController extends Controller
{
    public function channels(Request $request, PaymentFeeCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            // Required, not defaulted: a flow that forgets to say which fee it
            // is would be quoted another flow's rate here and only find out
            // when the order path charges a different number. Same reason
            // PaymentFeeCalculator takes `$audience` without a default.
            'audience' => ['required', 'in:ticket,registration,organizer'],
            // How many things are being bought. The platform margin is charged
            // per unit, so the preview has to be told the basket size or it
            // quotes a total the order path will not honour. Defaults to 1:
            // every flow except ticket purchase buys exactly one thing.
            'units' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $audience = match ($data['audience']) {
            'ticket' => PaymentFeeCalculator::AUDIENCE_TICKET,
            'registration' => PaymentFeeCalculator::AUDIENCE_REGISTRATION,
            'organizer' => PaymentFeeCalculator::AUDIENCE_ORGANIZER,
        };

        return ApiResponse::success($calculator->allChannels(
            (float) $data['amount'],
            $audience,
            (int) ($data['units'] ?? 1),
        ));
    }
}
