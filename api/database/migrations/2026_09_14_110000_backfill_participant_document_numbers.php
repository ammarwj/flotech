<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give already-paid ticket orders and registrations the numbers they were
     * settled without.
     *
     * The previous migration deliberately skipped this, reasoning that issuing
     * numbers after the fact would be back-dating documents. That was too
     * cautious, and wrong where it counts: the money **did** move, the amount
     * and the date are both recorded, and the payer can see the payment in
     * their dashboard. Leaving those rows blank does not avoid inventing a
     * document — it withholds one for a payment that really happened.
     *
     * The line that matters is `payment > 0`, not "before or after the
     * migration": a free entry has no bill, so it still gets nothing.
     *
     * Sequences are keyed on the row's OWN period (created_at for the invoice,
     * paid_at for the receipt), not today's, so a backfilled number sits in the
     * month it belongs to — exactly how 2026_07_13_100005 backfilled the plan
     * orders. Idempotent: rows that already carry a number are skipped, so the
     * live sequence is never disturbed.
     */
    public function up(): void
    {
        $this->backfill('ticket_orders', 'total_price', 'ticket', fn ($row) => $row->status === 'paid');
        $this->backfill('teams', 'payment_amount', 'registration', fn ($row) => $row->payment_status === 'paid');
    }

    /**
     * @param  callable(object): bool  $isPaid
     */
    protected function backfill(string $table, string $amountColumn, string $stream, callable $isPaid): void
    {
        $invoicePrefix = config("billing.{$stream}_invoice_prefix");
        $receiptPrefix = config("billing.{$stream}_receipt_prefix");

        // Continue from whatever the live sequence has already reached, so a
        // backfilled row can never collide with one issued since deploy.
        $invoiceSeq = $this->seenSequences($table, 'invoice_number', $invoicePrefix);
        $receiptSeq = $this->seenSequences($table, 'receipt_number', $receiptPrefix);

        DB::table($table)
            ->where($amountColumn, '>', 0)
            ->whereNull('invoice_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function ($row) use ($table, $isPaid, $invoicePrefix, $receiptPrefix, &$invoiceSeq, &$receiptSeq) {
                $period = Carbon::parse($row->created_at)->format('Y/m');
                $invoiceSeq[$period] = ($invoiceSeq[$period] ?? 0) + 1;

                $update = [
                    'invoice_number' => sprintf('%s/%s/%04d', $invoicePrefix, $period, $invoiceSeq[$period]),
                ];

                // A receipt only for money actually received; an unpaid order
                // keeps its bill and nothing else.
                if ($isPaid($row) && $row->paid_at) {
                    $paidPeriod = Carbon::parse($row->paid_at)->format('Y/m');
                    $receiptSeq[$paidPeriod] = ($receiptSeq[$paidPeriod] ?? 0) + 1;
                    $update['receipt_number'] = sprintf('%s/%s/%04d', $receiptPrefix, $paidPeriod, $receiptSeq[$paidPeriod]);
                }

                DB::table($table)->where('id', $row->id)->update($update);
            });
    }

    /**
     * Highest sequence already used per period, so the backfill appends rather
     * than colliding.
     *
     * @return array<string, int>
     */
    protected function seenSequences(string $table, string $column, string $prefix): array
    {
        $seen = [];

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'like', "{$prefix}/%")
            ->pluck($column)
            ->each(function (string $number) use (&$seen) {
                // <prefix>/<year>/<month>/<seq> — the period is everything
                // between the prefix and the sequence.
                $parts = explode('/', $number);
                $seq = (int) array_pop($parts);
                array_shift($parts);
                $period = implode('/', $parts);

                $seen[$period] = max($seen[$period] ?? 0, $seq);
            });

        return $seen;
    }

    public function down(): void
    {
        // Irreversible by design: which numbers were backfilled and which were
        // issued live is not recorded, and clearing both would strip documents
        // from payments made after deploy.
    }
};
