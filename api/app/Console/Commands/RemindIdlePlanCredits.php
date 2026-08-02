<?php

namespace App\Console\Commands;

use App\Models\EventPlanOrder;
use App\Notifications\PlanOrderIdle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nudge organizers holding a paid plan they have never spent.
 *
 * A credit never expires — taking it back would be taking money — so the only
 * lever available is a reminder. Without one the money simply sits in an orders
 * list nobody opens, and the organizer eventually forgets they are owed an
 * event.
 *
 * Idempotent by `idle_reminded_at`, which is why the column exists: a daily
 * sweep with no memory would mail the same person every day for as long as they
 * held the credit.
 */
class RemindIdlePlanCredits extends Command
{
    protected $signature = 'plan-orders:remind-idle {--dry-run : Tampilkan saja, jangan kirim email}';

    protected $description = 'Ingatkan organizer yang punya paket lunas tapi belum dipakai';

    public function handle(): int
    {
        $after = (int) config('billing.idle_credit_days');
        $repeat = (int) config('billing.idle_credit_repeat_days');

        if ($after <= 0) {
            $this->info('Pengingat kredit menganggur dimatikan (billing.idle_credit_days = 0).');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $orders = EventPlanOrder::query()
            // unconsumed() is the single definition of "still a credit" — it
            // already excludes top-up bills and orders a paid upgrade retired,
            // neither of which anyone should be nudged about.
            ->unconsumed()
            ->where('paid_at', '<=', $now->copy()->subDays($after))
            ->where(function ($q) use ($now, $repeat) {
                $q->whereNull('idle_reminded_at');

                // A repeat gap of 0 means "remind once, ever".
                if ($repeat > 0) {
                    $q->orWhere('idle_reminded_at', '<=', $now->copy()->subDays($repeat));
                }
            })
            ->with(['plan', 'organization.owner'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Tidak ada kredit menganggur yang perlu diingatkan.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($orders as $order) {
            $idleDays = $order->paid_at ? (int) $order->paid_at->diffInDays($now) : $after;
            $label = ($order->organization?->name ?? '?').' — '.($order->plan?->name ?? '?')." ({$idleDays} hari)";

            if ($dry) {
                $this->line("  akan diingatkan: {$label}");

                continue;
            }

            try {
                $order->organization?->owner?->notify(new PlanOrderIdle($order, $idleDays));
            } catch (Throwable $e) {
                // One bad address must not stop the sweep for everyone else.
                Log::error('Gagal mengirim pengingat kredit menganggur', [
                    'plan_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Stamped even when the owner is missing, so an organization without
            // one is not retried every single day forever.
            $order->forceFill(['idle_reminded_at' => $now])->save();
            $sent++;
        }

        $this->info($dry
            ? $orders->count().' kredit menganggur akan diingatkan.'
            : "{$sent} pengingat kredit menganggur terkirim.");

        return self::SUCCESS;
    }
}
