<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Split the single participant-facing service fee into a ticket rate and a
 * team-registration rate.
 *
 * Data only — `platform_settings` is a generic key/value table, so there is no
 * schema to change. The old key is renamed to the ticket rate and its value is
 * *copied* to the registration rate: whatever a super admin had set applies to
 * both flows the moment this deploys. Leaving the registration row absent would
 * fall through to the config default of 0, silently zeroing a fee nobody asked
 * to change.
 *
 * Idempotent in both directions — a second run finds nothing to rename and the
 * copy target already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')
            ->where('key', 'service_fee_amount')
            ->update(['key' => 'ticket_service_fee_amount']);

        $ticket = DB::table('platform_settings')
            ->where('key', 'ticket_service_fee_amount')
            ->first();

        // Nothing to carry over on a fresh install: with no override row at
        // all, both keys read their config default.
        if (! $ticket) {
            return;
        }

        $exists = DB::table('platform_settings')
            ->where('key', 'registration_service_fee_amount')
            ->exists();

        if (! $exists) {
            DB::table('platform_settings')->insert([
                // Spelled out because this is a raw insert: the table's primary
                // key is a UUID filled in by the model's HasUuids trait, which
                // the query builder never goes through.
                'id' => (string) Str::uuid(),
                'key' => 'registration_service_fee_amount',
                'value' => $ticket->value,
                'updated_by' => $ticket->updated_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->where('key', 'registration_service_fee_amount')
            ->delete();

        DB::table('platform_settings')
            ->where('key', 'ticket_service_fee_amount')
            ->update(['key' => 'service_fee_amount']);
    }
};
