<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The tiers organizers actually use: host, sponsor, media partner, supporter. */
    public function up(): void
    {
        Schema::table('event_sponsors', function (Blueprint $table) {
            $table->string('tier', 20)->default('sponsor')->change();
        });

        // Fold the old billing-style tiers into the new ones.
        DB::table('event_sponsors')->whereIn('tier', ['main', 'official'])->update(['tier' => 'sponsor']);
        DB::table('event_sponsors')->where('tier', 'supporting')->update(['tier' => 'supporter']);
    }

    public function down(): void
    {
        DB::table('event_sponsors')->whereIn('tier', ['host', 'media_partner'])->update(['tier' => 'sponsor']);
        DB::table('event_sponsors')->where('tier', 'supporter')->update(['tier' => 'supporting']);

        Schema::table('event_sponsors', function (Blueprint $table) {
            $table->string('tier', 20)->default('supporting')->change();
        });
    }
};
