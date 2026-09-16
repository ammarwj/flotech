<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referees and match staff — the people an event needs who belong to no team.
 *
 * They hang off `event_id`, not `team_id`, for the reason that decides the whole
 * shape: a referee is not on anyone's side. Putting them in `team_officials`
 * would make them a member of whichever team happened to hold the row, and every
 * reader of that table (bench lists, team cards, registration exports) would
 * show them there.
 *
 * `role_label` is free text on purpose, unlike `team_officials.role` which is a
 * key from `sport_official_roles`. A bench role is a property of the sport
 * ("pelatih" means the same thing in every football event); "Wasit Utama",
 * "Hakim Garis", "Panitia Lapangan" are what this particular committee decided
 * to call its people, and no catalogue of them would ever be right for the next
 * event.
 *
 * `matches.referee_id` is deliberately NOT added here — nothing asked for it.
 * The table is shaped so it could point at this one later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_personnel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('full_name');
            // referee|staff. Coarse on purpose: this is what "select all referees"
            // filters on, while role_label carries the actual job title.
            $table->string('kind', 20);
            $table->string('role_label', 60)->nullable();
            $table->text('photo_url')->nullable();
            // Same as a bench: no natural order, so the order they were typed in
            // is the order they are shown.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_personnel');
    }
};
