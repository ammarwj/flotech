<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the emergency contact IS — "Ibu", "Suami", "Teman sekamar".
 *
 * The third column of the one group on a race form that exists for safety
 * rather than for admin, and the name and number are worth less without it: a
 * marshal on the course with a phone in their hand is deciding how to open the
 * call, and "is this their parent or their office" changes what they say first.
 *
 * Free text with a short ceiling, the same call `gender` already made one
 * column up and for the same reason written there: which values are offered is
 * the form's business, not the column's. An enum would have to guess at a list
 * nobody has — "Ibu", "Ayah", "Suami", "Istri", "Kakak", "Teman", "Pelatih" is
 * already seven and still misses the one somebody needs.
 *
 * Nullable, like the pair it joins. Whether to demand any of the three is the
 * organizer's call and they make it on their registration form, not here.
 *
 * Separate migration rather than an edit to create_race_entries_table: that one
 * is recorded as Ran, so editing it would leave every database that applied it
 * without the column and report success — the trap
 * 2026_10_16_100002_add_payment_type_to_race_entries_table already names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->string('emergency_contact_relation', 50)
                ->nullable()
                ->after('emergency_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropColumn('emergency_contact_relation');
        });
    }
};
