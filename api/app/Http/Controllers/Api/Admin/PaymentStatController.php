<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide settled-payment figures, broken down by how people paid.
 *
 * Grouped under three keys so the next cut (per organizer, per month) can join
 * this endpoint without reshaping what a dashboard already reads — the same
 * reasoning as Admin\StatController's `events` key.
 *
 * **Counted from the order tables, never from the webhooks that settled them.**
 * Midtrans re-delivers notifications, so counting deliveries would bill a
 * single payment as many times as it was announced. The order rows are the
 * money: each one is written once, by the one service allowed to settle it.
 */
class PaymentStatController extends Controller
{
    /**
     * The three rails money arrives on, and the column names each one spells
     * differently.
     *
     * `amount` is what the seller charges — the organizer's ticket/registration
     * price, or the platform's own plan price. `gateway_fee` and `service_fee`
     * are the buyer's surcharge on top of it, so gross is their sum; the same
     * identity TicketOrder::getGrossAmountAttribute() publishes per row.
     * `gateway_tax` is NOT added: it is already counted inside `gateway_fee`.
     *
     * Spelled once because every breakdown below runs the same query per rail
     * with a different `GROUP BY`. A new rail is one entry here — and it must
     * be added, because a rail left out does not read as zero anywhere: its
     * revenue is simply absent from every figure on the page.
     *
     * @var array<string, array{table: string, amount: string}>
     */
    private const SOURCES = [
        'ticket_order' => ['table' => 'ticket_orders', 'amount' => 'total_price'],
        'team' => ['table' => 'teams', 'amount' => 'payment_amount'],
        'race_entry' => ['table' => 'race_entries', 'amount' => 'price'],
        'event_plan_order' => ['table' => 'event_plan_orders', 'amount' => 'amount'],
    ];

    /**
     * The column every rail above happens to spell the same way.
     *
     * Named once rather than per entry in SOURCES, because the one rail where
     * it differs is a difference of *meaning*, not of spelling:
     * `event_plan_orders.event_id` is null until an organizer spends the credit
     * on an event, so filtering by event deliberately drops paid plan credits
     * that nobody has claimed yet. That is the right answer for "what did this
     * event take in" — unspent credit belongs to the organization, not to any
     * event — and `/admin/plan-orders/idle` is where it is accounted for.
     */
    private const EVENT_COLUMN = 'event_id';

    /**
     * The key a null `payment_type`/`payment_channel` is reported under.
     *
     * Null is three real things — a manual or box-office order that never went
     * through Midtrans, a free one, and any gateway order settled before the
     * column existed — and all three are revenue. Dropping them would make the
     * number of groups change from request to request, the lesson already
     * written down for `hide_when_zero` in the landing-stats pattern.
     */
    private const UNKNOWN = 'unknown';

    /**
     * Midtrans' real cut on an instrument, as a percent of GROSS (what the
     * buyer was actually charged) — **not** the `gateway_fee` column, which is
     * our own published rate (0.7% + PPN for e-wallets) and can diverge from
     * what Midtrans actually withholds.
     *
     * Today this is one entry: GoPay settles at ~2% gross while we only bill
     * the buyer 0.7%+PPN, so every GoPay payment runs the platform a real
     * loss. It is cross-subsidised by the channels below (VA's flat fee,
     * QRIS-native) rather than being a bug — this map exists so that
     * cross-subsidy is visible on the dashboard instead of only in someone's
     * head. `qris` and `va` are deliberately absent: absent reads as "no known
     * gap", 0.0 would read as "confirmed zero gap", and the first is the true
     * state until someone has the real MDR for those too.
     *
     * Keyed by `payment_type` (Midtrans' own instrument, e.g. `gopay`), not by
     * our `payment_channel` grouping — QRIS-native and GoPay both report under
     * the `ewallet` channel but do not share a real MDR.
     *
     * @var array<string, float>
     */
    private const REAL_MDR_PERCENT = [
        'gopay' => 2.0,
    ];

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $event = $request->query('event_id');

        // Unknown values fall through to "no filter" rather than 422 — a stale
        // link or a typed URL should widen back to everything, not error out.
        $source = $request->query('source');
        $source = is_string($source) && array_key_exists($source, self::SOURCES) ? $source : null;

        // `by_source` is always every rail (it is what the "per jenis
        // pemasukan" tab shows, and the filter must not collapse it to one
        // bar), but `totals` has to reflect the active filter — it is the
        // number every card and breakdown above is implicitly "out of".
        $bySource = $this->bySource($from, $to, $event);
        $totals = $source ? $bySource[$source] : $bySource['_all'];
        unset($bySource['_all']);

        $byPaymentType = $this->breakdown('payment_type', $from, $to, $event, source: $source);

        return ApiResponse::success([
            'by_payment_type' => $byPaymentType,
            // Labelled here, unlike the instruments above: a channel's human
            // name lives in config/payment_fees.php and is already what the
            // checkout picker shows the buyer, so the dashboard must read the
            // same source rather than grow a second copy that drifts the day a
            // channel is renamed.
            'by_channel' => $this->breakdown('payment_channel', $from, $to, $event, withLabel: true, source: $source),
            'by_source' => $bySource,
            'totals' => $totals,
            // Derived from `by_payment_type`, not a second query — see
            // mdrSubsidy() for why it has to be the same numbers already on
            // screen rather than a figure that can disagree with them.
            'mdr_subsidy' => $this->mdrSubsidy($byPaymentType),
        ]);
    }

    /**
     * What Midtrans' real cut costs us beyond what we billed the buyer, for
     * every instrument we have a REAL_MDR_PERCENT figure for.
     *
     * Computed from the exact rows already returned in `by_payment_type`
     * rather than a fresh query against the order tables: a second query
     * here could round or filter differently and quietly disagree with the
     * breakdown sitting right above it on the page — the same reasoning
     * `bySource()` uses for `totals`.
     *
     * `gap` is money the platform absorbs: real MDR minus what we actually
     * billed as `gateway_fee`. It is cross-subsidised from other channels
     * (VA's flat fee, instruments with no known real-MDR gap), not a loss on
     * the platform as a whole — the UI is the one place that sentence has to
     * be said out loud, because the raw number alone reads as "we are losing
     * money".
     *
     * @param  list<array<string, mixed>>  $byPaymentType
     * @return list<array{payment_type: string, count: int, gross: float, real_mdr_percent: float, real_mdr_amount: float, billed_gateway_fee: float, gap: float}>
     */
    private function mdrSubsidy(array $byPaymentType): array
    {
        $rows = [];

        foreach ($byPaymentType as $row) {
            $type = $row['payment_type'];
            $percent = self::REAL_MDR_PERCENT[$type] ?? null;
            if ($percent === null) {
                continue;
            }

            $realMdrAmount = $row['gross'] * $percent / 100;

            $rows[] = [
                'payment_type' => $type,
                'count' => $row['count'],
                'gross' => $row['gross'],
                'real_mdr_percent' => $percent,
                'real_mdr_amount' => $realMdrAmount,
                'billed_gateway_fee' => $row['gateway_fee'],
                'gap' => $realMdrAmount - $row['gateway_fee'],
            ];
        }

        return $rows;
    }

    /**
     * One row per distinct value of `$column` across all three rails.
     *
     * There is deliberately no catalogue to pad this out with zeros, unlike
     * StatController printing every Event status: Midtrans names the
     * instruments, so the only list of them that cannot go stale is the one the
     * rows themselves carry.
     *
     * `$source` narrows to one rail's table (e.g. only `race_entries`) rather
     * than summing all four and throwing three away — the same row count a
     * naive filter-after-query would produce, at the cost of querying tables
     * the answer never needed.
     *
     * @return list<array<string, mixed>>
     */
    private function breakdown(string $column, ?string $from, ?string $to, ?string $event = null, bool $withLabel = false, ?string $source = null): array
    {
        $totals = [];
        $sources = $source ? [self::SOURCES[$source]] : self::SOURCES;

        foreach ($sources as $config) {
            $rows = $this->query($config['table'], $config['amount'], $from, $to, $event)
                ->selectRaw("coalesce({$column}, ?) as bucket", [self::UNKNOWN])
                ->groupBy('bucket')
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->bucket;
                $totals[$key] = $this->add($totals[$key] ?? $this->zero(), $row);
            }
        }

        // Biggest earner first: a dashboard reads top-down, and the ordering
        // must not depend on which rail happened to be queried first.
        uasort($totals, fn (array $a, array $b) => $b['gross'] <=> $a['gross']);

        return collect($totals)
            ->map(fn (array $sums, string $key) => [$column => $key]
                + ($withLabel ? ['label' => $this->channelLabel($key)] : [])
                + $sums)
            ->values()
            ->all();
    }

    /**
     * Per-rail figures, plus an `_all` roll-up `totals` reuses.
     *
     * Summed from the same per-rail queries rather than totalled separately, so
     * the cards and the breakdown under them cannot disagree — the same reason
     * StatController derives `active` from its own map.
     *
     * @return array<string, array<string, float|int>>
     */
    private function bySource(?string $from, ?string $to, ?string $event = null): array
    {
        $out = ['_all' => $this->zero()];

        foreach (self::SOURCES as $source => $config) {
            $row = $this->query($config['table'], $config['amount'], $from, $to, $event)->first();

            $out[$source] = $this->add($this->zero(), $row);
            $out['_all'] = $this->add($out['_all'], $row);
        }

        return $out;
    }

    /**
     * Settled rows only, with the per-rail column names normalised to one set
     * of aliases so every caller above can add them up the same way.
     *
     * `paid_at` is the filter and the gate: it is stamped by the one method
     * allowed to settle each kind of order, so "has a paid_at" and "the money
     * arrived" cannot drift apart the way a status value could.
     */
    private function query(string $table, string $amountColumn, ?string $from, ?string $to, ?string $event = null): Builder
    {
        return DB::table($table)
            ->whereNotNull('paid_at')
            ->when($from, fn (Builder $q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('paid_at', '<=', $to))
            // See EVENT_COLUMN: unclaimed plan credits carry a null event_id and
            // are meant to fall out of an event-scoped figure.
            ->when($event, fn (Builder $q) => $q->where(self::EVENT_COLUMN, $event))
            ->selectRaw('count(*) as count')
            ->selectRaw("coalesce(sum({$amountColumn}), 0) as amount")
            ->selectRaw('coalesce(sum(gateway_fee), 0) as gateway_fee')
            ->selectRaw('coalesce(sum(service_fee), 0) as service_fee');
    }

    /**
     * A channel's display name, from the config the checkout picker reads.
     *
     * A channel retired from the config still has orders behind it, so the key
     * is the fallback — a blank cell would read as missing data for money that
     * was really taken.
     */
    private function channelLabel(string $key): string
    {
        return config("payment_fees.channels.{$key}.label")
            ?? ($key === self::UNKNOWN ? 'Tanpa gateway' : $key);
    }

    /** @return array<string, float|int> */
    private function zero(): array
    {
        return ['count' => 0, 'amount' => 0.0, 'gateway_fee' => 0.0, 'service_fee' => 0.0, 'gross' => 0.0];
    }

    /**
     * @param  array<string, float|int>  $sums
     * @return array<string, float|int>
     */
    private function add(array $sums, ?object $row): array
    {
        if (! $row) {
            return $sums;
        }

        $amount = (float) $row->amount;
        $gatewayFee = (float) $row->gateway_fee;
        $serviceFee = (float) $row->service_fee;

        return [
            'count' => $sums['count'] + (int) $row->count,
            'amount' => $sums['amount'] + $amount,
            'gateway_fee' => $sums['gateway_fee'] + $gatewayFee,
            'service_fee' => $sums['service_fee'] + $serviceFee,
            // What the payer was actually charged — see SOURCES for why
            // gateway_tax is not part of this sum.
            'gross' => $sums['gross'] + $amount + $gatewayFee + $serviceFee,
        ];
    }
}
