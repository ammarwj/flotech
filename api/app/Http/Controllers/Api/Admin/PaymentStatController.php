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
     * The key a null `payment_type`/`payment_channel` is reported under.
     *
     * Null is three real things — a manual or box-office order that never went
     * through Midtrans, a free one, and any gateway order settled before the
     * column existed — and all three are revenue. Dropping them would make the
     * number of groups change from request to request, the lesson already
     * written down for `hide_when_zero` in the landing-stats pattern.
     */
    private const UNKNOWN = 'unknown';

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        // One call, then split: `totals` must be the sum of the rails it is
        // printed above, not a second query that can disagree with them.
        $bySource = $this->bySource($from, $to);
        $totals = $bySource['_all'];
        unset($bySource['_all']);

        return ApiResponse::success([
            'by_payment_type' => $this->breakdown('payment_type', $from, $to),
            // Labelled here, unlike the instruments above: a channel's human
            // name lives in config/payment_fees.php and is already what the
            // checkout picker shows the buyer, so the dashboard must read the
            // same source rather than grow a second copy that drifts the day a
            // channel is renamed.
            'by_channel' => $this->breakdown('payment_channel', $from, $to, withLabel: true),
            'by_source' => $bySource,
            'totals' => $totals,
        ]);
    }

    /**
     * One row per distinct value of `$column` across all three rails.
     *
     * There is deliberately no catalogue to pad this out with zeros, unlike
     * StatController printing every Event status: Midtrans names the
     * instruments, so the only list of them that cannot go stale is the one the
     * rows themselves carry.
     *
     * @return list<array<string, mixed>>
     */
    private function breakdown(string $column, ?string $from, ?string $to, bool $withLabel = false): array
    {
        $totals = [];

        foreach (self::SOURCES as $config) {
            $rows = $this->query($config['table'], $config['amount'], $from, $to)
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
    private function bySource(?string $from, ?string $to): array
    {
        $out = ['_all' => $this->zero()];

        foreach (self::SOURCES as $source => $config) {
            $row = $this->query($config['table'], $config['amount'], $from, $to)->first();

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
    private function query(string $table, string $amountColumn, ?string $from, ?string $to): Builder
    {
        return DB::table($table)
            ->whereNotNull('paid_at')
            ->when($from, fn (Builder $q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('paid_at', '<=', $to))
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
