<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many ties the first round of this category's bracket holds.
 *
 * Until now the answer was derived: a bracket is a power of two, so
 * `2^maxRound / 2` gave it. That stops being true the moment a bracket pairs as
 * many teams as it can per round and byes the odd one out — 36 → 18 → 9 → 5 →
 * 3 → 2 → 1 has five rounds and eighteen opening ties, where the formula says
 * sixteen.
 *
 * It cannot be derived from the round above either: a round of N parents came
 * from either 2N or 2N-1 children, and nothing in the tree says which. So it is
 * stored — null meaning "the old power-of-two rule", which is exactly what
 * every row written before this migration played.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('bracket_slots')->nullable()->after('knockout_plan');
        });
    }

    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn('bracket_slots');
        });
    }
};
