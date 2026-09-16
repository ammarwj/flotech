<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a name on an ID card into someone who can log in and work.
 *
 * Two columns, and the split between them is the whole point. `email` is what
 * the organizer typed — it stays exactly as typed even when no account came of
 * it, the same way `role_label` keeps free text the committee chose. `user_id`
 * is what the provisioning made of it, and it is the only thing authorization
 * ever reads.
 *
 * `nullOnDelete`, not cascade: deleting a user must not delete the event's
 * record that this person was its referee. The row survives with a null link,
 * which reads as "typed, no account" — the same state a row starts in.
 *
 * The reverse direction is the invariant that matters more, and it lives in
 * EventPersonnelService rather than here: dropping a personnel row does NOT
 * delete the account. The same person referees other events, and their access
 * to this one dies because the middleware looks for a *personnel row*, not
 * because the account vanished.
 *
 * The index is `['event_id', 'user_id']` because that is the exact question
 * EventPersonnelScope asks on every single officiating request: is this user a
 * member of this event's crew.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_personnel', function (Blueprint $table) {
            $table->string('email')->nullable()->after('full_name');
            $table->foreignUuid('user_id')->nullable()->after('email')
                ->constrained('users')->nullOnDelete();

            $table->index(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('event_personnel', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('email');
        });
    }
};
