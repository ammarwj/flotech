<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This account is holding a password it did not choose."
 *
 * Needed because the officiating invite mails a default password in its body —
 * a deliberate, user-chosen deviation from what Admin\UserController says about
 * out-of-band delivery. The deviation is only bounded if the default stops
 * working the moment it has been used once, and that has to be a column the
 * server reads: a frontend-only screen is a suggestion, not a control.
 *
 * Default false, so every existing account and every self-registered one is
 * untouched — the flag is set only by the flow that issues a password on
 * somebody's behalf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
