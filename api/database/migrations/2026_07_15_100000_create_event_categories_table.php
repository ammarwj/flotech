<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Splits an event into one-or-more competition categories (U17, U19, Woman, …).
 *
 * The format, bracket config, registration fee and team cap used to live on the
 * event itself; they move here so every category can run its own format at its
 * own price. Teams and matches gain a `category_id` — that is the unit the
 * scheduler, standings and knockout now operate on. Existing events are folded
 * into a single "Umum" category so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');                    // U17 | U19 | Woman | …
            $table->string('slug', 100);
            $table->string('tournament_format', 20);   // league | knockout_single | knockout_double | hybrid
            $table->json('bracket_config')->nullable();
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->integer('max_teams')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'slug']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->after('event_id')
                ->constrained('event_categories')->cascadeOnDelete();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->after('event_id')
                ->constrained('event_categories')->cascadeOnDelete();
        });

        $this->backfill();

        // Every team and match belongs to a category now that they're seeded.
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('category_id')->nullable(false)->change();
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->uuid('category_id')->nullable(false)->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['tournament_format', 'registration_fee', 'max_teams', 'bracket_config']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('tournament_format', 20)->default('league')->after('sport_type');
            $table->integer('max_teams')->nullable()->after('banner_url');
            $table->decimal('registration_fee', 12, 2)->default(0)->after('max_teams');
            $table->json('bracket_config')->nullable()->after('rules_config');
        });

        // Best-effort: fold the first category of each event back onto the event.
        foreach (DB::table('event_categories')->orderBy('sort_order')->get() as $category) {
            DB::table('events')->where('id', $category->event_id)->update([
                'tournament_format' => $category->tournament_format,
                'bracket_config' => $category->bracket_config,
                'registration_fee' => $category->registration_fee,
                'max_teams' => $category->max_teams,
            ]);
        }

        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('event_categories');
    }

    /**
     * Give every existing event a single "Umum" category holding its old format
     * and fee, then point that event's teams and matches at it.
     */
    private function backfill(): void
    {
        $now = now();

        foreach (DB::table('events')->get() as $event) {
            $categoryId = (string) Str::uuid();

            DB::table('event_categories')->insert([
                'id' => $categoryId,
                'event_id' => $event->id,
                'name' => 'Umum',
                'slug' => 'umum',
                'tournament_format' => $event->tournament_format,
                'bracket_config' => $event->bracket_config,
                'registration_fee' => $event->registration_fee,
                'max_teams' => $event->max_teams,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('teams')->where('event_id', $event->id)->update(['category_id' => $categoryId]);
            DB::table('matches')->where('event_id', $event->id)->update(['category_id' => $categoryId]);
        }
    }
};
