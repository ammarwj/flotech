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
 * set separately for participants paying an organizer and organizers paying
 * us. It is a preview of public pricing either way — neither rate is a secret,
 * and the number that gets charged is recomputed server-side at order time.
 */
class PublicPaymentController extends Controller
{
    public function channels(Request $request, PaymentFeeCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'audience' => ['nullable', 'in:participant,organizer'],
        ]);

        $audience = ($data['audience'] ?? 'participant') === 'organizer'
            ? PaymentFeeCalculator::AUDIENCE_ORGANIZER
            : PaymentFeeCalculator::AUDIENCE_PARTICIPANT;

        return ApiResponse::success($calculator->allChannels((float) $data['amount'], $audience));
    }
}
