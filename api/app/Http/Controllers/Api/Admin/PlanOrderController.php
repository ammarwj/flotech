<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventPlanOrderResource;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Services\PaymentRails;
use App\Services\EventPlanOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manual plan payments waiting on us.
 *
 * The counterpart to PaymentVerificationController, one level up: that one is
 * an org admin ruling on money paid into their *own* account, this one is a
 * super admin ruling on money paid into the platform's. Accepting here
 * activates a paid plan on nothing but someone's say-so, and the organizer
 * being credited is exactly the party who uploaded the receipt — so it can
 * never sit behind `tenant`, however convenient that would be.
 */
class PlanOrderController extends Controller
{
    public function __construct(
        protected EventPlanOrderService $orders,
        protected PaymentRails $rails,
    ) {}

    public function index(): JsonResponse
    {
        $pending = EventPlanOrder::query()
            ->awaitingVerification()
            ->with(['plan', 'organization'])
            ->latest('payment_proof_uploaded_at')
            ->get();

        return ApiResponse::success(EventPlanOrderResource::collection($pending));
    }

    public function approve(Request $request, EventPlanOrder $planOrder): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->orders->approveProof($planOrder, $admin);

        return ApiResponse::success(
            new EventPlanOrderResource($planOrder->fresh()->load(['plan', 'organization'])),
            'Pembayaran diterima. Paket siap dipakai untuk satu event.',
        );
    }

    public function reject(Request $request, EventPlanOrder $planOrder): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
        ])['reason'];

        $this->orders->rejectProof($planOrder, $reason, $this->rails->deadline());

        return ApiResponse::success(
            new EventPlanOrderResource($planOrder->fresh()->load(['plan', 'organization'])),
            'Bukti ditolak. Organizer dapat mengunggah ulang.',
        );
    }

    /**
     * Move an event onto a different paid plan.
     *
     * The escape hatch for the one dead end this billing model has: a plan locks
     * to its event at creation and there is no mid-event upgrade, so an
     * organizer who spent a Starter and then needs a second category cannot buy
     * their way out — the only other path is deleting the event, which throws
     * away its registrations, payments and wallet history.
     *
     * Deliberately super_admin, not org.admin: this hands out entitlements
     * unless a human checks the replacement credit is real, and the party who
     * benefits is exactly the one asking.
     *
     * The unique index on `event_id` is left alone. Releasing the old order and
     * claiming the new one in the same transaction keeps "one order, one event"
     * a database fact; relaxing the constraint to allow two would make every
     * reader of that pair ambiguous forever.
     */
    public function reassignPlan(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'plan_order_id' => ['required', 'uuid'],
        ], [
            'plan_order_id.required' => 'Pilih paket pengganti.',
        ]);

        $replacement = EventPlanOrder::whereKey($data['plan_order_id'])
            ->where('organization_id', $event->organization_id)
            ->unconsumed()
            ->first();

        if (! $replacement) {
            return ApiResponse::error(
                'Paket pengganti harus milik organisasi yang sama, sudah lunas, dan belum dipakai.',
                ['plan_order_id' => ['Paket tidak bisa dipakai.']],
                422,
            );
        }

        DB::transaction(function () use ($event, $replacement) {
            // Released, not deleted: the old order is still the receipt for
            // money that was paid, and its invoice has already been emailed.
            EventPlanOrder::where('event_id', $event->id)->update([
                'event_id' => null,
                'consumed_at' => null,
            ]);

            $claimed = EventPlanOrder::whereKey($replacement->id)
                ->whereNull('event_id')
                ->update(['event_id' => $event->id, 'consumed_at' => now()]);

            abort_if($claimed === 0, 409, 'Paket itu baru saja dipakai untuk event lain.');

            $event->update(['plan_id' => $replacement->plan_id]);
        });

        return ApiResponse::success(
            new EventResource($event->fresh()->load(['plan.features', 'categories'])),
            'Paket event dipindahkan.',
        );
    }
}
