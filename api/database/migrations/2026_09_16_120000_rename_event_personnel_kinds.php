<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `wasit`/`staf` → `referee`/`staff`.
 *
 * These two values are about to stop being a label on an ID card and start being
 * an authorization boundary: a middleware branches on `kind` to decide who may
 * enter scores and who may approve a lineup. A boundary that is checked in PHP,
 * in routes, in middleware aliases and in TypeScript should read in one
 * vocabulary — an Indonesian value compared against an English constant is
 * exactly the kind of pair that drifts, and the drift here is silent: the wrong
 * spelling doesn't error, it just admits or refuses the wrong person.
 *
 * The labels humans read stay Indonesian; see EventPersonnel::KIND_LABELS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_personnel')->where('kind', 'wasit')->update(['kind' => 'referee']);
        DB::table('event_personnel')->where('kind', 'staf')->update(['kind' => 'staff']);
    }

    public function down(): void
    {
        DB::table('event_personnel')->where('kind', 'referee')->update(['kind' => 'wasit']);
        DB::table('event_personnel')->where('kind', 'staff')->update(['kind' => 'staf']);
    }
};
