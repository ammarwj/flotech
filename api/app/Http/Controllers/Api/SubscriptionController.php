<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CheckoutRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\MidtransService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function __construct(protected MidtransService $midtrans) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $subs = $org->subscriptions()->with('plan')->latest()->get();

        return ApiResponse::success(SubscriptionResource::collection($subs));
    }

    /**
     * Start a subscription checkout: create a pending subscription and a
     * Midtrans Snap transaction. Without Midtrans credentials the subscription
     * is auto-activated (dev convenience).
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        $plan = Plan::findOrFail($request->input('plan_id'));
        $cycle = $request->string('billing_cycle')->value();

        $amount = $cycle === 'yearly' ? (float) $plan->price_yearly : (float) $plan->price_monthly;
        $startsAt = Carbon::now();
        $expiresAt = $cycle === 'yearly' ? $startsAt->copy()->addYear() : $startsAt->copy()->addMonth();
        $orderId = 'SUB-'.Str::upper(Str::random(10));

        $subscription = $org->subscriptions()->create([
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'status' => 'past_due', // awaiting payment; flips to active on settlement
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'midtrans_order_id' => $orderId,
        ]);

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($amount)],
            ['first_name' => $org->name, 'email' => $org->contact_email],
        );

        if ($snap['mock']) {
            // No payment gateway configured — activate immediately for dev.
            $this->activate($subscription);
        }

        return ApiResponse::success([
            'subscription' => new SubscriptionResource($subscription->load('plan')),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'],
        ], 'Checkout dibuat', 201);
    }

    /**
     * Apply a paid subscription to its organization.
     */
    public function activate(Subscription $subscription): void
    {
        $subscription->update([
            'status' => 'active',
            'paid_at' => Carbon::now(),
        ]);

        $subscription->organization->update([
            'plan_id' => $subscription->plan_id,
            'plan_expires_at' => $subscription->expires_at,
        ]);
    }
}
