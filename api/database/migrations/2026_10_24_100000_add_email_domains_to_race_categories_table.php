<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which email domains may enter a race category: "Mahasiswa UB" sells cheaper
 * than "Umum", so the cheaper row needs a door only a campus address opens.
 *
 * **Invariant: null = no requirement, and `[]` is never stored.** Two spellings
 * of "open to anyone" are two branches that will disagree — the same reason
 * `help_text` on a registration form field is nulled when blank rather than
 * kept as `''`. RaceCategoryController normalises an empty list to null on the
 * way in.
 *
 * This is NOT the `race_category_prices` case. There a missing row must REFUSE
 * (an unpriced window is not free), the one place in this schema where "no
 * value" may not read as a default. Here it must ACCEPT: a category the
 * organizer never put a condition on is a category anyone may enter, which is
 * what every row that exists today already is — so this column changes no
 * existing behaviour at all.
 *
 * Matching is EXACT, not by suffix: `ub.ac.id` admits `@ub.ac.id` and refuses
 * `@student.ub.ac.id`, which the organizer adds as its own entry. See
 * RaceCategory::acceptsEmail() for why that is the safer default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_categories', function (Blueprint $table) {
            $table->json('email_domains')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('race_categories', function (Blueprint $table) {
            $table->dropColumn('email_domains');
        });
    }
};
