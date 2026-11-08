<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Put `bib_generator` into the catalogue of a database that is already running.
 *
 * Both halves, because CLAUDE.md's catalogue invariant needs both and either
 * one alone fails silently: a `plan_features` value with no `feature_definitions`
 * row never appears on the pricing page at all, and a definition with no value
 * appears as a struck-through row on every plan — which is the right rendering
 * for Pro and the wrong one for Professional.
 *
 * Carries its own data rather than calling the seeders, the precedent set by
 * seed_per_event_plan_catalogue and align_plan_catalogue_with_production:
 * deploy.sh runs `migrate --force` on every release but seeds only under
 * SEED=1, precisely so a super admin's edits at /admin/plans survive a deploy.
 * An existing database is never re-seeded, so editing PlanSeeder and
 * FeatureDefinitionSeeder (done in the same change) is not enough on its own.
 *
 * Professional only. Pro deliberately does not carry it — see PlanSeeder, and
 * note that this keeps Pro a subset of Professional in every key, so
 * PlanGate::planCovers() leaves the Pro → Professional upgrade monotone.
 * Retired plans (`basic`, `starter`) are not touched: nobody is buying them,
 * and a feature nobody paid for must not appear on an invoice that predates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->upsert('feature_definitions', ['feature_key' => 'bib_generator'], [
            'feature_label' => 'Generator e-BIB',
            'feature_group' => 'certificate',
            'feature_type' => 'boolean',
            'description' => 'Cetak nomor dada digital peserta lomba dari template, lengkap dengan QR check-in.',
            'sort_order' => 150,
        ]);

        $professionalId = DB::table('plans')->where('slug', 'professional')->value('id');

        if ($professionalId !== null) {
            $this->upsert(
                'plan_features',
                ['plan_id' => $professionalId, 'feature_key' => 'bib_generator'],
                ['value' => 'true'],
            );
        }
    }

    /**
     * Insert-or-update a row on a catalogue table.
     *
     * Not `updateOrInsert()`: both tables take a uuid primary key with no
     * database default, so an insert through that helper fails the NOT NULL on
     * `id` — and it only fails on the branch where the row does not exist yet,
     * which is every fresh install and no existing one. Same shape
     * seed_per_event_plan_catalogue uses, and for the same reason.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, array $match, array $values): void
    {
        $now = now();
        $existing = DB::table($table)->where($match);

        if ($existing->exists()) {
            $existing->update($values + ['updated_at' => $now]);

            return;
        }

        DB::table($table)->insert($values + $match + [
            'id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The value row goes, the definition stays.
     *
     * Deliberately asymmetric, and it mirrors how `starter` was retired: a
     * definition with no values is a harmless struck-through row, while
     * deleting it would orphan the key for any plan a super admin had
     * meanwhile granted it to by hand — and an orphaned key is invisible on
     * every screen rather than wrong on one.
     */
    public function down(): void
    {
        DB::table('plan_features')->where('feature_key', 'bib_generator')->delete();
    }
};
