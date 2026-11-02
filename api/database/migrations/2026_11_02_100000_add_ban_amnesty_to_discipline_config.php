<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spell the new amnesty key into the rulebook of every sport that already has
 * one, so /admin/sports opens on a complete object rather than a field whose
 * value only exists in code.
 *
 * Nothing changes behaviour: 'off' is what DisciplineRules::DEFAULTS already
 * resolves an absent key to. That is the point — the column stays a record of
 * what the sport decided, and a sport whose rulebook is missing a key it is
 * entitled to set is the shape an admin then "fixes" by typing a value nobody
 * asked for.
 *
 * Only sports that book players at all are touched, and only keys that are
 * absent are written: an admin who has already answered keeps their answer.
 * Sports with no card stat keep a null config, because giving them one would
 * make the feature claim to be configured where it can never fire.
 */
return new class extends Migration
{
    private const KEY = 'ban_amnesty_round';

    /**
     * The value that means "no amnesty", written literally rather than imported
     * from DisciplineRules: a migration records what the column held on the day
     * it ran, and pointing it at a constant that later changes rewrites history.
     */
    private const NO_AMNESTY = 'off';

    public function up(): void
    {
        foreach (DB::table('sports')->whereNotNull('discipline_config')->get() as $sport) {
            $config = json_decode((string) $sport->discipline_config, true);

            if (! is_array($config) || array_key_exists(self::KEY, $config)) {
                continue;
            }

            $config[self::KEY] = self::NO_AMNESTY;

            DB::table('sports')->where('id', $sport->id)->update([
                'discipline_config' => json_encode($config),
            ]);
        }

        // The catalog is remembered forever; without this the new key stays
        // invisible until some admin write happens to flush it.
        Catalog::flush();
    }

    public function down(): void
    {
        foreach (DB::table('sports')->whereNotNull('discipline_config')->get() as $sport) {
            $config = json_decode((string) $sport->discipline_config, true);

            if (! is_array($config) || ! array_key_exists(self::KEY, $config)) {
                continue;
            }

            // Only the no-op value is withdrawn. An amnesty an organizer or
            // admin actually chose is theirs, and dropping it on a rollback
            // would quietly put banned players back on the field.
            if ($config[self::KEY] !== self::NO_AMNESTY) {
                continue;
            }

            unset($config[self::KEY]);

            DB::table('sports')->where('id', $sport->id)->update([
                'discipline_config' => json_encode($config),
            ]);
        }

        Catalog::flush();
    }
};
