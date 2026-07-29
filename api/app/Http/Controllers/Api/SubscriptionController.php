<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CheckoutRequest;
use App\Http\Resources\PlatformBankAccountResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
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

        // pay() re-derives the rail, which would flip a manual bill to gateway
        // and strand the receipt already sitting in the super admin's queue.
        if ($subscription->isAwaitingVerification()) {
            return ApiResponse::error('Bukti pembayaranmu sedang diperiksa admin.', null, 422);
        }

        $result = $this->subscriptions->pay($subscription);

        return ApiResponse::success($this->checkoutPayload($result), 'Pembayaran dibuka');
    }

    /**
     * The organizer's transfer receipt for a manual plan payment.
     *
     * Behind `org.admin` like the rest of this controller: uploading proof
     * commits the organization to a bill, which is not a gate operator's call.
     */
    public function proof(Request $request, string $organization, Subscription $subscription): JsonResponse
    {
        $subscription = $this->authorizeSubscription($request, $subscription);

        $data = $request->validate([
            'payment_proof_url' => ['required', 'string', 'url', 'max:2048'],
        ], [
            'payment_proof_url.required' => 'Bukti pembayaran wajib diunggah.',
        ]);

        $this->subscriptions->submitProof($subscription, $data['payment_proof_url']);

        return ApiResponse::success(
            new SubscriptionResource($subscription->fresh()->load('plan')),
            'Bukti pembayaran terkirim. Menunggu verifikasi admin flo-event.',
        );
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
     * @param  array{subscription: Subscription, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}  $result
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $result): array
    {
        return [
            'subscription' => new SubscriptionResource($result['subscription']),
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
