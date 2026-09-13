<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the sequential document numbers every billing document carries:
 * `INV/2026/07/0001`, restarting each month.
 *
 * One implementation for all three streams (plan orders, ticket orders,
 * registration fees) on purpose. A second copy of this would drift from the
 * first, and the drift is invisible until two documents share a number — by
 * which point both are already in somebody's accounting.
 *
 * What separates the streams is the PREFIX, not the code: see the
 * `*_prefix` keys in config/billing.php.
 */
class DocumentNumberService
{
    /**
     * @param  class-string<Model>  $model  table to scan for the month's last number
     * @param  'invoice_number'|'receipt_number'  $column
     * @param  string  $prefix  e.g. `INV`, `KW-T`
     */
    public function next(string $model, string $column, string $prefix): string
    {
        $period = Carbon::now()->format('Y/m');

        return DB::transaction(function () use ($model, $column, $prefix, $period) {
            // Postgres rejects FOR UPDATE alongside an aggregate ("FOR UPDATE is
            // not allowed with aggregate functions"), so take the highest row and
            // lock *that* rather than locking a max(). Sequences are zero-padded,
            // so lexical order is numeric order.
            $last = $model::where($column, 'like', "{$prefix}/{$period}/%")
                ->orderByDesc($column)
                ->lockForUpdate()
                ->value($column);

            $seq = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

            return sprintf('%s/%s/%04d', $prefix, $period, $seq);
        });
    }
}
