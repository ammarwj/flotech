<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventPlanOrderResource;
use App\Models\EventPlanOrder;
use App\Services\EventPlanOrderService;
use App\Support\ApiResponse;
use App\Support\DomainAddonRules;
use App\Support\DomainAddons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Domains organizers have paid for and we still have to go and register.
 *
 * Only the `register` kind reaches here. `own` and `subdomain` are fulfilled by
 * being paid for — the organizer already holds the name, or we hold the zone —
 * and EventPlanOrderService::applyDomainAddon() stamps them on the spot. A queue
 * that also listed those would be a queue of work nobody has to do, which is a
 * queue people stop opening.
 *
 * Its own controller rather than another method on Admin\PlanOrderController:
 * that one is a payment-verification queue (is this receipt genuine?), this one
 * is a fulfilment queue (has the name been bought?). Same distinction as
 * RaceRefundController next to RaceEntryController.
 */
class DomainOrderController extends Controller
{
    public function __construct(protected EventPlanOrderService $orders) {}

    /**
     * The queue, plus what has already been done.
     *
     * `fulfilled` rides along in the same payload rather than sitting behind a
     * `?status=`, for the reason the refund queue gives: this list is usually
     * empty, and a screen that cannot show last week's work reads as a feature
     * that lost its data.
     */
    public function index(): JsonResponse
    {
        $pending = EventPlanOrder::query()
            ->awaitingDomainFulfilment()
            ->with(['organization', 'plan', 'domainEvent'])
            ->get();

        $fulfilled = EventPlanOrder::query()
            ->where('domain_kind', DomainAddons::KIND_REGISTER)
            ->whereNotNull('domain_fulfilled_at')
            ->with(['organization', 'plan', 'domainEvent', 'domainFulfiller'])
            ->latest('domain_fulfilled_at')
            // Capped rather than paginated, same call as the verification
            // history: this answers "did we already buy that one?", asked days
            // after the fact.
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'pending' => EventPlanOrderResource::collection($pending),
            'fulfilled' => EventPlanOrderResource::collection($fulfilled),
        ]);
    }

    /**
     * Record that the name has been registered — and attach it.
     *
     * The domain may differ from what the organizer asked for: the name they
     * wanted was taken, or they left the choice to us. `domain_note` is what
     * explains the difference to them, and it is the only thing on their screen
     * that accounts for a paid order carrying a name they did not pick.
     */
    public function fulfil(Request $request, EventPlanOrder $planOrder): JsonResponse
    {
        if (! $planOrder->isAwaitingDomainFulfilment()) {
            return ApiResponse::error('Pesanan domain ini tidak sedang menunggu registrasi.', null, 422);
        }

        $validator = Validator::make($request->all(), [
            // Required here, unlike at checkout: the whole point of this action
            // is that the name now exists.
            'domain_name' => ['required', 'string', 'max:253'],
            'domain_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'domain_name.required' => 'Masukkan domain yang berhasil diregistrasi.',
        ]);

        // Same availability check the organizer's checkout ran, re-run here
        // because time has passed and the name may have been claimed since —
        // including by another order in this very queue. Excluding this order's
        // own event so re-entering the name it already asked for is not a
        // collision with itself.
        DomainAddonRules::check(
            $validator,
            excluding: $planOrder->domainEvent,
            // The order already settled the kind; this payload carries only a
            // name, so without it every check below would be skipped.
            kind: DomainAddons::KIND_REGISTER,
        );

        $data = $validator->validate();

        $this->orders->fulfilDomain(
            $planOrder,
            $request->user(),
            $data['domain_name'],
            $data['domain_note'] ?? null,
        );

        return ApiResponse::success(
            new EventPlanOrderResource(
                $planOrder->refresh()->load(['organization', 'plan', 'domainEvent', 'domainFulfiller'])
            ),
            'Domain ditandai sudah diregistrasi',
        );
    }
}
