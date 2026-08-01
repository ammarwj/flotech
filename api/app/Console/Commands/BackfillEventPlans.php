<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: give every event that predates per-event billing a plan.
 *
 * Before this change the entitlement lived on the organization, so events carry
 * no plan of their own and PlanGate — which now reads `events.plan_id` — would
 * grant them nothing. A running tournament would lose its tickets, its
 * certificates and its gallery mid-competition.
 *
 * Everything is backfilled to Professional rather than to whatever the
 * organization happened to be on. The old caps do not map cleanly: a Starter
 * org could already be running an event with six categories, and handing it a
 * plan that allows one would leave it permanently in violation of a limit it
 * never agreed to. Nobody is charged for the difference — these events were
 * already paid for under the old model.
 *
 * Idempotent: only events without a plan are touched, and the order row is
 * guarded by its own existence check, so re-running is a no-op.
 */
class BackfillEventPlans extends Command
{
    protected $signature = 'events:backfill-plan {--dry-run : Hitung saja, jangan tulis apa pun}';

    protected $description = 'Beri paket Professional ke event yang lahir sebelum billing per-event.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $plan = Plan::where('slug', 'professional')->first();

        if (! $plan) {
            $this->error('Paket "professional" tidak ada. Jalankan migrasi katalog dulu.');

            return self::FAILURE;
        }

        $pending = Event::whereNull('plan_id')->count();

        if ($pending === 0) {
            $this->info('Tidak ada event yang perlu di-backfill.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$pending} event akan diberi paket {$plan->name} (dry run, tidak ada yang ditulis).");

            return self::SUCCESS;
        }

        $done = 0;

        Event::whereNull('plan_id')->chunkById(200, function ($events) use ($plan, &$done) {
            foreach ($events as $event) {
                DB::transaction(function () use ($event, $plan, &$done) {
                    $event->update(['plan_id' => $plan->id]);

                    // The receipt for a purchase that predates purchases. It
                    // exists so /organizer/billing can explain where the event's
                    // plan came from, and so `event_id` is spoken for — an event
                    // with a plan but no order would otherwise look like a credit
                    // that leaked.
                    $exists = EventPlanOrder::where('event_id', $event->id)->exists();

                    if (! $exists) {
                        EventPlanOrder::create([
                            'organization_id' => $event->organization_id,
                            'plan_id' => $plan->id,
                            'event_id' => $event->id,
                            'consumed_at' => $event->created_at,
                            'amount' => 0,
                            'status' => 'paid',
                            'paid_at' => $event->created_at,
                            'payment_method' => 'gateway',
                            'payment_type' => 'legacy_migration',
                            // Deliberately no invoice or receipt number. An issued
                            // number is a document that was generated and emailed;
                            // minting one for a payment that never happened would
                            // slip a fabricated invoice into a real sequence. Both
                            // columns are nullable-unique, so many nulls are fine,
                            // and nextNumber()'s LIKE scan skips them.
                        ]);
                    }

                    $done++;
                });
            }
        });

        // Re-count rather than trust the loop counter. The first run of this
        // command reported 100 events done while writing none of them:
        // `plan_id` was missing from Event::$fillable, so update() dropped it
        // silently and only the order rows landed. A backfill that cannot tell
        // you it did nothing is worse than one that crashes.
        $remaining = Event::whereNull('plan_id')->count();

        if ($remaining > 0) {
            $this->error("{$done} event diproses tapi {$remaining} masih tanpa paket — tidak ada yang tersimpan.");

            return self::FAILURE;
        }

        $this->info("{$done} event diberi paket {$plan->name}.");

        return self::SUCCESS;
    }
}
