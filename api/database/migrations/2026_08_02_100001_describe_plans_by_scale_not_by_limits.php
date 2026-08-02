<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Say who each plan is for, not what its numbers are.
 *
 * The old descriptions ("4 kategori, 128 peserta per kategori") repeated the
 * exact figures the feature rows print two lines below them, so the one line a
 * card has to answer "is this me?" was spent restating a table. Naming the scale
 * instead — a company league, a city-level championship, a provincial or
 * national event — is the thing a visitor cannot work out from the numbers on
 * their own.
 *
 * `seed_per_event_plan_catalogue` carries the new text too, so a database
 * migrating from scratch never sees the old wording and this is a no-op there.
 * It exists for the ones that already ran that migration. Matching on the old
 * text also means a super admin who has already rewritten a description at
 * /admin/plans keeps theirs.
 */
return new class extends Migration
{
    /** @return array<string, array{0: string, 1: string}> slug => [old, new] */
    private function rewrites(): array
    {
        return [
            'starter' => [
                'Untuk satu event kecil — 1 kategori, 32 peserta.',
                'Turnamen internal atau komunitas — liga kantor, antar-kelas, fun match.',
            ],
            'pro' => [
                'Untuk satu event menengah — 4 kategori, 128 peserta per kategori.',
                'Kejuaraan antar-klub atau antar-sekolah tingkat kota dan kabupaten.',
            ],
            'professional' => [
                'Untuk satu event besar — kategori & peserta tanpa batas.',
                'Kejuaraan tingkat provinsi & nasional, atau event multi-cabang.',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->rewrites() as $slug => [$old, $new]) {
            DB::table('plans')
                ->where('slug', $slug)
                ->where('description', $old)
                ->update(['description' => $new, 'updated_at' => now()]);
        }
    }

    /** Reversible, unlike the landing-copy migration: nothing here is false. */
    public function down(): void
    {
        foreach ($this->rewrites() as $slug => [$old, $new]) {
            DB::table('plans')
                ->where('slug', $slug)
                ->where('description', $new)
                ->update(['description' => $old, 'updated_at' => now()]);
        }
    }
};
