<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The roles a team official can hold (Pelatih Kepala, Manajer Tim, Ofisial…),
 * kept per sport for the same reason positions are: it is admin-managed
 * vocabulary, and a sport an admin adds later should be able to name its own.
 *
 * Nothing to backfill on team_officials — it is created alongside this — but the
 * sports already in the database do need their defaults, and re-running
 * SportSeeder to get them would reset every edit an admin has made to the other
 * sport columns. So the seed happens here, once, like sport_positions did.
 */
return new class extends Migration
{
    /**
     * A bench looks the same in every sport, so every sport starts with the same
     * list. Trimming it is the admin's business from here on.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const DEFAULTS = [
        ['head_coach', 'Pelatih Kepala'],
        ['assistant_coach', 'Asisten Pelatih'],
        ['team_manager', 'Manajer Tim'],
        ['physio', 'Fisioterapis'],
        ['official', 'Ofisial'],
    ];

    public function up(): void
    {
        Schema::create('sport_official_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sport_id')->constrained('sports')->cascadeOnDelete();
            // What team_officials.role stores. The label is free to change; this isn't.
            $table->string('role_key', 30);
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['sport_id', 'role_key']);
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_official_roles');
    }

    private function seedDefaults(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::table('sports')->pluck('id') as $sportId) {
            foreach (self::DEFAULTS as $order => [$key, $label]) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'sport_id' => $sportId,
                    'role_key' => $key,
                    'label' => $label,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table('sport_official_roles')->insert($rows);

        // The catalog is remembered forever; without this the roles stay
        // invisible until something else happens to flush it.
        Catalog::flush();
    }
};
