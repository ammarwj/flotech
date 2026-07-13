<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CheckoutRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BillingDocumentService;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * These routes sit under `organizations/{organization}/...`, so every action
 * that also binds a {subscription} has to declare `$organization` positionally
 * ahead of it — unused in the body, but dropping it shifts the bindings and
 * Laravel resolves the wrong model.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected BillingDocumentService $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $subs = $org->subscriptions()->with('plan')->latest()->get();

        return ApiResponse::success(SubscriptionResource::collection($subs));
    }

    /**
     * Start a subscription checkout: create a pending subscription and a
     * Midtrans Snap transaction.
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        $plan = Plan::findOrFail($request->input('plan_id'));

        $result = $this->subscriptions->checkout($org, $plan, $request->string('billing_cycle')->value());

        return ApiResponse::success($this->checkoutPayload($result), 'Checkout dibuat', 201);
    }

    /**
     * Reopen payment for an unpaid invoice.
     */
    public function pay(Request $request, string $organization, Subscription $subscription): JsonResponse
    {
        $subscription = $this->authorizeSubscription($request, $subscription);

        if ($subscription->status !== 'past_due') {
            return ApiResponse::error('Tagihan ini tidak menunggu pembayaran.', null, 422);
        }

        $result = $this->subscriptions->pay($subscription);

        return ApiResponse::success($this->checkoutPayload($result), 'Pembayaran dibuka');
    }

    public function invoice(Request $request, string $organization, Subscription $subscription): Response
    {
        return $this->documents->invoice($this->authorizeSubscription($request, $subscription));
    }

    public function receipt(Request $request, string $organization, Subscription $subscription): Response
    {
        $subscription = $this->authorizeSubscription($request, $subscription);

        if (! $subscription->paid_at) {
            return ApiResponse::error('Kwitansi baru tersedia setelah pembayaran lunas.', null, 403);
        }

        return $this->documents->receipt($subscription);
    }

    /**
     * A subscription id in the URL is not proof of ownership — the tenant
     * middleware only vouches for the organization, not for this row.
     */
    protected function authorizeSubscription(Request $request, Subscription $subscription): Subscription
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        abort_if($subscription->organization_id !== $org->id, 404);

        return $subscription;
    }

    /**
     * @param  array{subscription: Subscription, snap_token: string|null, redirect_url: string|null, mock: bool}  $result
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $result): array
    {
        return [
            'subscription' => new SubscriptionResource($result['subscription']),
            'snap_token' => $result['snap_token'],
            'redirect_url' => $result['redirect_url'],
            'mock' => $result['mock'],
        ];
    }
}
