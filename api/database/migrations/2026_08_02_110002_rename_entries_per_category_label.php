<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Peserta per kategori" counts entries, not people.
 *
 * A twenty-man football squad and a single badminton player are both 1, so the
 * label was reliably read as twenty times more room than it grants — the
 * description existed only to argue with the word above it. Naming the unit
 * "entri" lets the description say what an entry *is* instead.
 *
 * Matches the old text, so a super admin who has already renamed it at
 * /admin/plans keeps their wording. FeatureDefinitionSeeder carries the same
 * copy for fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_definitions')
            ->where('feature_key', 'max_teams_per_category')
            ->where('feature_label', 'Peserta per kategori')
            ->update([
                'feature_label' => 'Entri per kategori',
                'description' => '1 tim / 1 pemain tunggal / 1 pasangan ganda dihitung 1 entri.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('feature_definitions')
            ->where('feature_key', 'max_teams_per_category')
            ->where('feature_label', 'Entri per kategori')
            ->update([
                'feature_label' => 'Peserta per kategori',
                'description' => '1 tim / 1 pemain tunggal / 1 pasangan ganda dihitung 1 peserta.',
                'updated_at' => now(),
            ]);
    }
};
