<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two roles added to the shared bench vocabulary: Medis and Admin.
 *
 * Same reason create_sport_official_roles_table seeded itself instead of asking
 * for SportSeeder to be re-run: the seeders carry the new entries for fresh
 * databases, but running one against production would overwrite every edit an
 * admin has made to the other sport columns.
 *
 * Idempotent, and it never touches a row that already exists — a sport whose
 * admin renamed or deleted "medic" keeps that decision.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string}> */
    private const ADDITIONS = [
        ['medic', 'Medis'],
        ['admin', 'Admin'],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::table('sports')->pluck('id') as $sportId) {
            $existing = DB::table('sport_official_roles')
                ->where('sport_id', $sportId)
                ->pluck('role_key')
                ->all();

            // Append to the end of whatever order this sport currently has,
            // rather than assuming the defaults are still five rows long.
            $order = (int) DB::table('sport_official_roles')
                ->where('sport_id', $sportId)
                ->max('sort_order');

            foreach (self::ADDITIONS as [$key, $label]) {
                if (in_array($key, $existing, true)) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'sport_id' => $sportId,
                    'role_key' => $key,
                    'label' => $label,
                    'sort_order' => ++$order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table('sport_official_roles')->insert($rows);

        Catalog::flush();
    }

    /**
     * Only the untouched rows go back: a role an official is already assigned to
     * is vocabulary in use, and SportController refuses to delete those too.
     */
    public function down(): void
    {
        $keys = array_column(self::ADDITIONS, 0);

        $inUse = DB::table('team_officials')
            ->whereIn('role', $keys)
            ->distinct()
            ->pluck('role')
            ->all();

        DB::table('sport_official_roles')
            ->whereIn('role_key', array_diff($keys, $inUse))
            ->delete();

        Catalog::flush();
    }
};
