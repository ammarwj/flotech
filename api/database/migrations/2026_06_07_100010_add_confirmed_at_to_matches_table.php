<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // A finished result counts toward standings / bracket only once
            // confirmed. Null = entered but awaiting confirmation.
            $table->timestamp('confirmed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
