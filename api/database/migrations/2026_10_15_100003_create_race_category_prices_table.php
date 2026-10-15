<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one category costs during one period. The whole early-bird feature is
 * this table.
 *
 * **Invariant: a missing row means NOT SOLD during that period — not free, and
 * not inherited.** This is the one place in this schema where "no value" must
 * *not* read as "the default value", and it runs against two neighbours that go
 * the other way: `ticket_category_days.quota` null means inherit the category's,
 * and `landing_stat_settings` null means inherit the catalogue. Read as free, a
 * category whose price the organizer has not filled in yet signs people up for
 * nothing; read as inherited, there is nothing to inherit from. RacePricing
 * returns null and the public form refuses with a 422.
 *
 * Test it by COMPARING a category that has a price row for the current period
 * (accepted) against one that has none (422), in a single test. Asserting the
 * early-bird price is correct would pass even if an absent row read as 0,
 * because in that test the row is there.
 *
 * The unique index is what keeps one category from holding two prices for one
 * period — two rows nobody notices, and `priceFor()` silently taking whichever
 * the database happened to return first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_category_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('race_category_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('race_period_id')->constrained()->cascadeOnDelete();
            // No nullable and no default. A row exists because the organizer
            // typed a price; "not sold then" is said by having no row at all,
            // which is why there is nothing here for a null to mean.
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['race_category_id', 'race_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_category_prices');
    }
};
