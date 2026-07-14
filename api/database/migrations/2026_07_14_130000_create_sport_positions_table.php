<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the old free-text `players.position` values map to, per sport. Keys
     * are matched case-insensitively; anything not listed is cleared, because
     * guessing at a roster we can't read would be worse than an empty field.
     *
     * Covers both the frontend's old suggestion list (web/lib/positions.ts) and
     * the shorthand DemoEventSeeder used to write ("GK", "Striker", "Spiker").
     *
     * @var array<string, array<string, string>>
     */
    private const LEGACY = [
        'football' => [
            'kiper' => 'goalkeeper', 'gk' => 'goalkeeper',
            'bek' => 'defender',
            'bek sayap' => 'wing_back',
            'gelandang' => 'midfielder',
            'gelandang serang' => 'attacking_midfielder',
            'sayap' => 'winger',
            'penyerang' => 'forward', 'striker' => 'forward',
        ],
        'mini_soccer' => [
            'kiper' => 'goalkeeper',
            'bek' => 'defender',
            'gelandang' => 'midfielder',
            'sayap' => 'winger',
            'penyerang' => 'forward', 'striker' => 'forward',
        ],
        'futsal' => [
            'kiper' => 'goalkeeper',
            'anchor' => 'anchor',
            'flank' => 'flank',
            'pivot' => 'pivot',
        ],
        'badminton' => [
            'tunggal' => 'singles',
            'ganda' => 'doubles',
        ],
        'padel' => [
            'drive' => 'drive',
            'reves' => 'reves',
        ],
        'volleyball' => [
            'setter' => 'setter',
            'outside hitter' => 'outside_hitter', 'spiker' => 'outside_hitter',
            'middle blocker' => 'middle_blocker', 'blocker' => 'middle_blocker',
            'opposite' => 'opposite',
            'libero' => 'libero',
        ],
    ];

    public function up(): void
    {
        Schema::create('sport_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sport_id')->constrained('sports')->cascadeOnDelete();
            // What players.position stores. The label is free to change; this isn't.
            $table->string('position_key', 30);
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['sport_id', 'position_key']);
        });

        $this->backfillPlayers();
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_positions');
    }

    /**
     * Rewrite existing rosters from free text to keys. Without this, the first
     * participant to open an old roster and hit save would be rejected for data
     * they never typed — position is validated against the master from now on.
     */
    private function backfillPlayers(): void
    {
        foreach (self::LEGACY as $sport => $map) {
            foreach ($map as $label => $key) {
                DB::table('players')
                    ->whereIn('team_id', $this->teamIdsOfSport($sport))
                    ->whereRaw('LOWER(TRIM(position)) = ?', [$label])
                    ->update(['position' => $key]);
            }

            // Hand-typed values with no home in this sport's master.
            DB::table('players')
                ->whereIn('team_id', $this->teamIdsOfSport($sport))
                ->whereNotNull('position')
                ->whereNotIn('position', array_values($map))
                ->update(['position' => null]);
        }

        // Sports the admin added themselves have no position master at all, so
        // nothing a player carries there can be valid.
        DB::table('players')
            ->whereNotNull('position')
            ->whereIn('team_id', DB::table('teams')
                ->join('events', 'events.id', '=', 'teams.event_id')
                ->whereNotIn('events.sport_type', array_keys(self::LEGACY))
                ->select('teams.id'))
            ->update(['position' => null]);
    }

    private function teamIdsOfSport(string $sport): Builder
    {
        return DB::table('teams')
            ->join('events', 'events.id', '=', 'teams.event_id')
            ->where('events.sport_type', $sport)
            ->select('teams.id');
    }
};
