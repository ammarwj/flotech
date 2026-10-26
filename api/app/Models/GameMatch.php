<?php

namespace App\Models;

use App\Services\RubberService;
use App\Support\RegistrationForm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single fixture between two teams. Named GameMatch because `Match` is a
 * reserved word in PHP; the underlying table is still `matches`.
 */
class GameMatch extends Model
{
    use HasUuids;

    protected $table = 'matches';

    protected $fillable = [
        'event_id',
        'category_id',
        'stage',
        'round',
        'group_name',
        'bracket',
        'order',
        'leg',
        'home_team_id',
        'away_team_id',
        'home_score',
        'away_score',
        'home_penalty',
        'away_penalty',
        'sets',
        'scheduled_at',
        'venue',
        // Catatan bebas organizer, terbit ke halaman publik saat terisi. Ditulis
        // hanya lewat `MatchController::updateSchedule()`.
        'notes',
        'status',
        'confirmed_at',
        // Jam pertandingan. Tanpa baris ini `$model->update()` menelannya tanpa
        // error — kegagalan diam yang sudah pernah terjadi di `events:backfill-plan`.
        'period',
        'clock_started_at',
        'clock_elapsed_seconds',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'order' => 'integer',
            'leg' => 'integer',
            'sets' => 'array',
            'confirmed_at' => 'datetime',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'home_penalty' => 'integer',
            'away_penalty' => 'integer',
            'scheduled_at' => 'datetime',
            'period' => 'integer',
            'clock_started_at' => 'datetime',
            'clock_elapsed_seconds' => 'integer',
        ];
    }

    /**
     * Kickoff is always stored as a UTC instant.
     *
     * Clients send an offset-bearing ISO string ("...T10:00:00+07:00"). Without
     * this, Eloquent's fromDateTime() formats that Carbon as-is and lands the
     * venue-local wall clock raw in a UTC column — a 10:00 WIB kickoff reads
     * back as 17:00. Same reason ScheduleService calls ->utc() on every write.
     *
     * A set-only Attribute bypasses the datetime cast on write, so this returns
     * the DB-ready string itself; reads still go through the cast.
     */
    protected function scheduledAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * A squad tie is born with the partai its category's template calls for.
     *
     * Hooked here rather than at each call site because fixtures are created in
     * ten places (ScheduleService's league/knockout/hybrid paths, manual entry,
     * bracket seeding) and every one of them would have to remember. The service
     * no-ops for every category that isn't a racket-sport squad tie.
     *
     * Model events are the hook, so anything running WithoutModelEvents (the
     * seeders) has to call RubberService::seedFor() itself — same caveat that
     * makes Catalog::flush() explicit there.
     */
    protected static function booted(): void
    {
        static::created(fn (GameMatch $match) => app(RubberService::class)->seedFor($match));

        /**
         * Jam yang berjalan pada laga yang statusnya bukan `ongoing` adalah
         * keadaan yang saling membantah, dan papan skor yang ditinggal menyala
         * akan terus berdetak setelah laganya selesai.
         *
         * Dipasang di sini, bukan di tiap pintu yang menulis status
         * (`MatchController::updateStatus`, `MatchResultService::apply`, dan
         * kedua pintu jam), karena menitipkan "jangan lupa bekukan jamnya" ke
         * empat tempat berarti salah satunya akan lupa — persis alasan hook
         * `created` di atas ada.
         *
         * Sisa detiknya **dilipat**, tidak dibuang: menit laga yang berhenti di
         * 67:14 adalah bagian dari hasilnya, dan membuangnya membuat laporan
         * pertandingan kehilangan lama laga yang sebenarnya.
         */
        static::saving(function (GameMatch $match) {
            if (! $match->isDirty('status') || $match->status === 'ongoing') {
                return;
            }

            if ($match->clock_started_at === null) {
                return;
            }

            $match->clock_elapsed_seconds = max(
                0,
                (int) $match->clock_elapsed_seconds
                    + $match->clock_started_at->diffInSeconds(now(), absolute: false),
            );
            $match->clock_started_at = null;
        });
    }

    /**
     * What a fixture has to carry to render as a card, for every reader of a
     * whole category's schedule.
     *
     * Written once because there are four of them — the organizer's schedule,
     * the public one, the officiating crew's, and a single fixture read cold —
     * and they must agree: a list that looks right on one screen and is missing
     * the club names on another is the "two readers of one rule" bug this
     * codebase keeps refusing. The three that existed had already been pasted
     * three times.
     *
     * `category.event` is load-bearing twice over: the `clock` block derives its
     * rules from both, and the schedule tags below are read off the event's
     * registration form. Without it MatchResource pulls it per row — 158
     * fixtures = 316 silent extra queries, since preventLazyLoading is off.
     *
     * The rosters are the one conditional part, and both conditions are about
     * cost, not correctness: a racket squad tie names its partai lineups from
     * them, and an event whose form prints a player answer on the card reads
     * them for that. An event that does neither must not pay for two squads per
     * fixture.
     *
     * @return list<string>
     */
    public static function fixtureRelations(EventCategory $category): array
    {
        $needsRosters = $category->usesRubbers()
            || RegistrationForm::forEvent($category->event)->hasScheduleTags('player');

        return [
            ...($needsRosters ? ['homeTeam.players', 'awayTeam.players'] : ['homeTeam', 'awayTeam']),
            ...($category->usesRubbers() ? ['rubbers'] : []),
            'category.event',
        ];
    }

    /**
     * fixtureRelations() for ONE already-fetched fixture — every door that
     * answers with a single card after writing to it.
     *
     * Needed because those doors cannot ask before the query: the category is
     * what decides which relations are wanted, and they are handed a match id.
     * So the rule is applied after the fact, through the same method, rather
     * than each door guessing: a card that loses its club names the moment a
     * score is saved, and gets them back on the next refetch, is exactly the
     * half-application this avoids.
     */
    public function loadFixtureRelations(): static
    {
        $this->loadMissing('category.event');

        return $this->category
            ? $this->load(self::fixtureRelations($this->category))
            : $this->load(['homeTeam', 'awayTeam']);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function rubbers(): HasMany
    {
        return $this->hasMany(MatchRubber::class, 'match_id')->orderBy('order');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(PlayerMatchStat::class, 'match_id');
    }

    /**
     * Cards shown to the benches in this fixture.
     *
     * Its own relation, never folded into `stats()`: an official is not a player
     * (see the create_official_match_stats_table migration), and a caller that
     * got both from one list would have to branch on which owner column is
     * filled. Everything reading `stats()` today is about players and stays that
     * way.
     */
    public function officialStats(): HasMany
    {
        return $this->hasMany(OfficialMatchStat::class, 'match_id');
    }

    /**
     * The team sheets handed in for this fixture — at most two, one per side.
     *
     * A pre-kickoff submission, not a record of who took the field: a named
     * substitute may never come on. Nothing that counts appearances or serves
     * bans may read this.
     */
    public function lineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class, 'match_id');
    }

    public function isFinished(): bool
    {
        return $this->status === 'finished'
            && $this->home_score !== null
            && $this->away_score !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
