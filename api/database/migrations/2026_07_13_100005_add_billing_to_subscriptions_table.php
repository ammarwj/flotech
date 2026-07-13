<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('invoice_number', 30)->nullable()->unique()->after('plan_id');
            $table->string('receipt_number', 30)->nullable()->unique()->after('invoice_number');
            $table->string('payment_type', 50)->nullable()->after('midtrans_order_id');
            $table->text('midtrans_token')->nullable()->after('payment_type');
        });

        $this->backfillNumbers();

        // Same guarantee ticket_orders and teams already have: a re-delivered
        // webhook must never match two rows.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique('midtrans_order_id');
        });
    }

    /**
     * Give existing rows the numbers they were created without: an invoice for
     * every subscription, a receipt only for the ones that were actually paid.
     */
    protected function backfillNumbers(): void
    {
        $invoicePrefix = config('billing.invoice_prefix', 'INV');
        $receiptPrefix = config('billing.receipt_prefix', 'KW');
        $invoiceSeq = [];
        $receiptSeq = [];

        DB::table('subscriptions')->orderBy('created_at')->orderBy('id')->each(
            function ($row) use ($invoicePrefix, $receiptPrefix, &$invoiceSeq, &$receiptSeq) {
                $issuedAt = Carbon::parse($row->created_at);
                $period = $issuedAt->format('Y/m');
                $invoiceSeq[$period] = ($invoiceSeq[$period] ?? 0) + 1;

                $update = [
                    'invoice_number' => sprintf('%s/%s/%04d', $invoicePrefix, $period, $invoiceSeq[$period]),
                ];

                if ($row->paid_at) {
                    $paidPeriod = Carbon::parse($row->paid_at)->format('Y/m');
                    $receiptSeq[$paidPeriod] = ($receiptSeq[$paidPeriod] ?? 0) + 1;
                    $update['receipt_number'] = sprintf('%s/%s/%04d', $receiptPrefix, $paidPeriod, $receiptSeq[$paidPeriod]);
                }

                DB::table('subscriptions')->where('id', $row->id)->update($update);
            }
        );
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
            $table->dropUnique(['invoice_number']);
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['invoice_number', 'receipt_number', 'payment_type', 'midtrans_token']);
        });
    }
};
