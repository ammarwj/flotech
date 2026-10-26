<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two participant rails that accept a discount code, in one migration
     * because the five columns are the same five columns. Ticket orders are
     * deliberately NOT included — spectator tickets take no code.
     *
     * @var list<string>
     */
    private const TABLES = ['teams', 'race_entries'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // The FK is for the usage ledger (how many times was this code
                // used?), and it goes null when the code row is deleted. The
                // four columns beside it are a *snapshot*: an issued invoice
                // must keep reading what was actually charged even after the
                // organizer edits or deletes the code. Same reason
                // `withdrawals` snapshots the destination account and
                // `race_entries` snapshots its period price.
                $table->foreignUuid('event_discount_code_id')->nullable()
                    ->constrained('event_discount_codes')->nullOnDelete();
                $table->string('discount_code', 40)->nullable();
                $table->string('discount_type', 10)->nullable();
                $table->decimal('discount_value', 12, 2)->nullable();
                // What was taken off the catalogue price. The net price column
                // (`teams.payment_amount` / `race_entries.price`) is already
                // net of it — and that net IS what the wallet is credited, which
                // is how "the organizer bears the discount" is enforced without
                // a second rule anywhere. The documents reconstruct the list
                // price from net + this.
                $table->decimal('discount_amount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('event_discount_code_id');
                $table->dropColumn(['discount_code', 'discount_type', 'discount_value', 'discount_amount']);
            });
        }
    }
};
