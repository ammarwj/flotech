<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanOrder\CheckoutRequest;
use App\Http\Resources\EventPlanOrderResource;
use App\Http\Resources\PlatformBankAccountResource;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\BillingDocumentService;
use App\Services\EventPlanOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buying plans on behalf of an organization.
 *
 * These routes sit under `organizations/{organization}/...`, so every action
 * that also binds a {planOrder} has to declare `$organization` positionally
 * ahead of it — unused in the body, but dropping it shifts the bindings and
 * Laravel resolves the wrong model.
 */
class PlanOrderController extends Controller
{
    public function __construct(
        protected EventPlanOrderService $orders,
        protected BillingDocumentService $documents,
    ) {}

    /**
     * Every order this organization has raised, paid or not.
     *
     * There is deliberately no second endpoint for "credits available to
     * spend": the resource carries `event_id` and `consumed_at`, so the client
     * filters unspent ones itself. One endpoint, one shape.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $orders = $org->planOrders()->with(['plan', 'event'])->latest()->get();

        return ApiResponse::success(EventPlanOrderResource::collection($orders));
    }

    /**
     * Buy a plan: create a pending order and open its payment.
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        $plan = Plan::findOrFail($request->input('plan_id'));

        $result = $this->orders->checkout($org, $plan);

        return ApiResponse::success($this->checkoutPayload($result), 'Checkout dibuat', 201);
    }

    /**
     * Reopen payment for an unpaid invoice.
     */
    public function pay(Request $request, string $organization, EventPlanOrder $planOrder): JsonResponse
    {
        $planOrder = $this->authorizeOrder($request, $planOrder);

        if ($planOrder->status !== 'past_due') {
            return ApiResponse::error('Tagihan ini tidak menunggu pembayaran.', null, 422);
        }

        // pay() re-derives the rail, which would flip a manual bill to gateway
        // and strand the receipt already sitting in the super admin's queue.
        if ($planOrder->isAwaitingVerification()) {
            return ApiResponse::error('Bukti pembayaranmu sedang diperiksa admin.', null, 422);
        }

        $result = $this->orders->pay($planOrder);

        return ApiResponse::success($this->checkoutPayload($result), 'Pembayaran dibuka');
    }

    /**
     * The organizer's transfer receipt for a manual plan payment.
     *
     * Behind `org.admin` like the rest of this controller: uploading proof
     * commits the organization to a bill, which is not a gate operator's call.
     */
    public function proof(Request $request, string $organization, EventPlanOrder $planOrder): JsonResponse
    {
        $planOrder = $this->authorizeOrder($request, $planOrder);

        $data = $request->validate([
            'payment_proof_url' => ['required', 'string', 'url', 'max:2048'],
        ], [
            'payment_proof_url.required' => 'Bukti pembayaran wajib diunggah.',
        ]);

        $this->orders->submitProof($planOrder, $data['payment_proof_url']);

        return ApiResponse::success(
            new EventPlanOrderResource($planOrder->fresh()->load('plan')),
            'Bukti pembayaran terkirim. Menunggu verifikasi admin flo-event.',
        );
    }

    public function invoice(Request $request, string $organization, EventPlanOrder $planOrder): Response
    {
        return $this->documents->invoice($this->authorizeOrder($request, $planOrder));
    }

    public function receipt(Request $request, string $organization, EventPlanOrder $planOrder): Response
    {
        $planOrder = $this->authorizeOrder($request, $planOrder);

        if (! $planOrder->paid_at) {
            return ApiResponse::error('Kwitansi baru tersedia setelah pembayaran lunas.', null, 403);
        }

        return $this->documents->receipt($planOrder);
    }

    /**
     * An order id in the URL is not proof of ownership — the tenant middleware
     * only vouches for the organization, not for this row.
     */
    protected function authorizeOrder(Request $request, EventPlanOrder $planOrder): EventPlanOrder
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        abort_if($planOrder->organization_id !== $org->id, 404);

        return $planOrder;
    }

    /**
     * @param  array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}  $result
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $result): array
    {
        return [
            'plan_order' => new EventPlanOrderResource($result['order']),
            'snap_token' => $result['snap_token'],
            'redirect_url' => $result['redirect_url'],
            'mock' => $result['mock'],
            // Same shape every "start a payment" response has, so the client
            // reads one branch for tickets, registrations and plans alike.
            'payment_method' => $result['payment_method'],
            'bank_account' => $result['bank_account']
                ? new PlatformBankAccountResource($result['bank_account'])
                : null,
        ];
    }
}
