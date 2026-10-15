<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\PaymentFeeCalculator;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fee preview for the channel picker. Global, not event-keyed — the gateway
 * fee is the same everywhere, so one endpoint serves all checkout flows.
 *
 * `audience` is the one thing that does differ: the platform's own margin is
 * set separately for each of the four flows — a participant buying tickets, a
 * participant paying a team's registration fee, a runner paying a fun run entry
 * fee, and an organizer buying a plan from us. `units` is the second: the ticket
 * margin is per seat, so a basket of three carries three of them, while the
 * other three are each bought once. It is a
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
            'audience' => ['required', 'in:ticket,registration,race_entry,organizer'],
            // How many things are being bought. The platform margin is charged
            // per unit, so the preview has to be told the basket size or it
            // quotes a total the order path will not honour. Defaults to 1:
            // every flow except ticket purchase buys exactly one thing.
            //
            // The ceiling is the ticket flow's own, because that is the only
            // flow that ever sends more than 1 and this endpoint must accept
            // every basket the order path will. A tighter cap here is the worst
            // kind of refusal: the picker fetches no channels, renders nothing,
            // and the buyer is left on a form that cannot be submitted with no
            // field to look at — which is exactly what a flat `max:100` did to
            // a 101-seat order.
            'units' => ['nullable', 'integer', 'min:1', 'max:'.TicketService::MAX_PAID_UNITS_PER_ORDER],
        ]);

        // No `default` arm, deliberately: a `match` with no default throws on
        // an unhandled value, so adding a word to the rule above without adding
        // it here fails loudly in development rather than quoting some other
        // flow's rate in production.
        $audience = match ($data['audience']) {
            'ticket' => PaymentFeeCalculator::AUDIENCE_TICKET,
            'registration' => PaymentFeeCalculator::AUDIENCE_REGISTRATION,
            'race_entry' => PaymentFeeCalculator::AUDIENCE_RACE_ENTRY,
            'organizer' => PaymentFeeCalculator::AUDIENCE_ORGANIZER,
        };

        return ApiResponse::success($calculator->allChannels(
            (float) $data['amount'],
            $audience,
            (int) ($data['units'] ?? 1),
        ));
    }
}
