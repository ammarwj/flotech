<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamOfficial;
use App\Support\SquadRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The post-match report — the sheet the panitia files once a fixture is over:
 * masthead, kickoff details, the scoreline with its periods, and both squads
 * with the stats that were recorded against them.
 *
 * Modelled on the paper MATCH SUMMARY, with one rule running through it: every
 * cell the database can answer is printed filled, and every cell it cannot is
 * printed **blank and ruled** rather than dropped. Cuaca, temperatur, penonton
 * and warna kostum are nowhere in the schema and are nobody's to invent — the
 * form is handed to a panitia who writes them in, exactly as the album already
 * does with Tempat Lahir and Alamat. Adding columns for them would be a
 * different feature, and a report that quietly omitted them would be a worse
 * document than the paper one it replaces.
 *
 * **Lineups are read here for presentation only.** DisciplineService states that
 * `match_lineups` may not be read by anything counting appearances or serving
 * bans, and that stays true: this prints who was *named*, under the heading the
 * manager named them with, and nothing downstream reads what it prints. The
 * arrow is one-way, the same way LineupService reads DisciplineService and never
 * the reverse.
 */
class MatchReportService
{
    /**
     * How many of the sport's stat columns fit beside a name.
     *
     * The two squads print side by side, as they do on the paper form, so each
     * table has half an A4 to work with. Four is what fits before the name
     * column starts wrapping every row; basket's fifth (blok) is the one that
     * falls off. The app's own stat editor is where a full tally is read — this
     * is a sheet for a clipboard.
     */
    protected const MAX_STAT_COLUMNS = 4;

    /**
     * How many ruled lines each of the five filing tables gets at minimum.
     *
     * They exist to be written on. A table sized to exactly the rows the
     * database can answer would print a single line for a match with one goal,
     * and the panitia who needs to add the minute beside it — or the goal the
     * stat editor never received — would have nowhere to put it. Padding is the
     * table; the filled rows are a head start.
     */
    protected const MIN_SUBSTITUTION_ROWS = 7;

    protected const MIN_LIST_ROWS = 6;

    /**
     * Rows one player may contribute to one list.
     *
     * player_match_stats is an aggregate, so a typo of 400 goals would print
     * four hundred rows and the sheet would stop being one page. The cap is
     * deliberately well above anything a real match produces.
     */
    protected const MAX_ROWS_PER_PLAYER = 10;

    public function __construct(protected PdfImageService $images) {}

    /**
     * The dompdf builder. Returns the builder (not ->output() bytes) so the
     * controller can call ->download($name) — same shape as TeamAlbumService.
     */
    public function build(GameMatch $match): DomPdf
    {
        return Pdf::loadHTML($this->html($match))->setPaper('a4', 'portrait');
    }

    /**
     * The rendered sheet, before dompdf turns it into a page.
     *
     * Split out of build() for the reason TeamAlbumService::html() was: dompdf's
     * output is compressed streams, so a test holding the PDF bytes can only
     * assert that *something* was produced — which is exactly the assertion that
     * stays green when a row stops being filled in.
     */
    public function html(GameMatch $match): string
    {
        return view('pdf.match-report', $this->payload($match))->render();
    }

    /**
     * Everything the template prints, already formatted.
     *
     * Blade runs the child section before the layout, so nothing here may be
     * left for the view to format — the same trap BillingDocumentService has a
     * comment about.
     *
     * @return array<string, mixed>
     */
    public function payload(GameMatch $match): array
    {
        $match->loadMissing([
            'event.organization',
            'category',
            'homeTeam.players',
            'homeTeam.officials',
            'awayTeam.players',
            'awayTeam.officials',
            'stats',
            'rubbers',
            'lineups.players.player',
            'lineups.officials.official',
        ]);

        $event = $match->event;
        $sport = $event->sport_type;
        $tz = $event->timezone;
        $kickoff = $match->scheduled_at ? Carbon::parse($match->scheduled_at)->timezone($tz) : null;

        $columns = array_slice(Catalog::statColumns($sport), 0, self::MAX_STAT_COLUMNS);
        $shorts = $this->positionShorts($sport);

        // player_id => [stat_key => value]. One pass over rows already loaded,
        // so a squad table costs no query per player.
        $stats = $match->stats
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($s) => [$s->stat_key => (int) $s->value]));

        $sheets = $match->lineups->keyBy('team_id');

        return [
            'event' => $event,
            'category' => $match->category,
            'match' => $match,
            'organizerLogo' => $this->images->dataUri($event->organization?->logo_url),
            'sportLabel' => Catalog::sport($sport)['name'] ?? null,
            'phase' => $this->phase($match),
            // Date and kickoff in one cell, the way the form has them. The
            // zone's own abbreviation, not a hardcoded "WIB": an event in
            // Makassar prints WITA, which is what the paper form carries too.
            'scheduleLabel' => $kickoff
                ? $kickoff->locale('id')->translatedFormat('d F Y').', '.$kickoff->format('H:i').' '.$kickoff->format('T')
                : 'Belum dijadwalkan',
            'venue' => $match->venue ?: $event->location_name,
            // Just the number: the column reads "Durasi (menit)" and the two
            // boxes beside it are the extra-time halves, so "90 menit ( ) ( )"
            // wrapped to two lines and said "menit" about boxes that are not
            // necessarily minutes of anything yet.
            'durationLabel' => Catalog::sport($sport)['default_match_minutes'] ?? null,
            'periods' => $this->periods($match),
            'rubbers' => $this->rubbers($match),
            'columns' => $columns,
            'home' => $this->side($match->homeTeam, $sheets->get($match->home_team_id), $sport, $columns, $stats, $shorts),
            'away' => $this->side($match->awayTeam, $sheets->get($match->away_team_id), $sport, $columns, $stats, $shorts),
            // What the POS codes stand for. Printed because the codes are
            // derived from labels an admin owns and may rename: without it the
            // sheet would carry an abbreviation whose expansion exists only in
            // the app the reader is holding a printout instead of.
            'positionLegend' => $this->positionLegend($match, $sport, $shorts),
            // The five filing tables of the paper form. Each is a pair of
            // half-width columns, home on the left and away on the right, so
            // they are built as one structure rather than ten.
            'substitutions' => $this->substitutions($match, $sport),
            'incidents' => $this->incidents($match, $sport, $stats),
            // Printed as a note rather than used as a gate. A finished result is
            // a result; whether the panitia has ratified it is a second fact,
            // and a sheet that stayed silent about it would let an unconfirmed
            // scoreline circulate looking final.
            'confirmed' => $match->isConfirmed(),
            'printedAt' => Carbon::now($tz)->locale('id')->translatedFormat('d F Y, H:i'),
        ];
    }

    /**
     * One squad, in the order the sheet prints it.
     *
     * Three groups, and the third is the one that matters: a player who has
     * stats recorded against them but was never named on the sheet still
     * appears, under "Lainnya". The alternative is a goal that is in the
     * database, on the scoreline, and nowhere on the report of the match that
     * produced it — and nobody reading the printed sheet could tell.
     *
     * With no sheet at all (lineups are optional; most events never use them)
     * the whole roster prints as one list, which is what the paper form is when
     * a manager fills it in by hand.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @param  Collection<string, Collection<string, int>>  $stats
     * @param  array<string, string>  $shorts
     * @return array<string, mixed>
     */
    protected function side(?Team $team, ?MatchLineup $sheet, ?string $sport, array $columns, Collection $stats, array $shorts = []): array
    {
        if (! $team) {
            return ['team' => 'TBD', 'logo' => null, 'groups' => [], 'officials' => []];
        }

        $roster = $team->players->keyBy('id');
        $row = fn (Player $player) => [
            'number' => $player->jersey_number ?: '',
            'name' => $player->full_name,
            // The code when there is one, the full label when there is not —
            // positionShorts() hands back an empty map rather than a lossy one.
            'position' => $shorts[$player->position] ?? Catalog::positionLabel($sport, $player->position) ?? '',
            'stats' => collect($columns)
                ->mapWithKeys(fn ($c) => [$c['key'] => $stats[$player->id][$c['key']] ?? ''])
                ->all(),
        ];

        if (! $sheet || $sheet->players->isEmpty()) {
            return [
                'team' => $team->name,
                'logo' => $this->images->dataUri($team->logo_url),
                'groups' => [['label' => 'Daftar pemain', 'rows' => $roster->values()->map($row)->all()]],
                'officials' => $this->officials($team->officials, $sport),
            ];
        }

        $named = [];
        $groups = [];

        foreach ([['starter', 'Pemain inti'], ['substitute', 'Pemain cadangan']] as [$role, $label]) {
            $rows = [];

            foreach ($sheet->players->where('role', $role) as $entry) {
                if (! $entry->player) {
                    continue;
                }

                $named[$entry->player_id] = true;
                $rows[] = $row($entry->player);
            }

            $groups[] = ['label' => $label, 'rows' => $rows];
        }

        $extra = $roster
            ->reject(fn (Player $p) => isset($named[$p->id]))
            ->filter(fn (Player $p) => $stats->has($p->id))
            ->values()
            ->map($row)
            ->all();

        if ($extra) {
            $groups[] = ['label' => 'Lainnya (tercatat statistik)', 'rows' => $extra];
        }

        return [
            'team' => $team->name,
            'logo' => $this->images->dataUri($team->logo_url),
            'groups' => $groups,
            // The bench as the manager named it, falling back to the team's own
            // list when there is no sheet — same fallback as the players above.
            'officials' => $sheet->officials->isNotEmpty()
                ? $this->officials($sheet->officials->map(fn ($e) => $e->official)->filter(), $sport)
                : $this->officials($team->officials, $sport),
        ];
    }

    /**
     * @param  Collection<int, TeamOfficial>  $officials
     * @return array<int, array{name: string, role: string}>
     */
    protected function officials(Collection $officials, ?string $sport): array
    {
        return $officials->map(fn ($official) => [
            'name' => $official->full_name,
            // Catalog returns null for a role the sport has no catalogue for,
            // or one an admin renamed away — a blank column would read as a bug.
            'role' => Catalog::officialRoleLabel($sport, $official->role) ?? 'Ofisial',
        ])->values()->all();
    }

    /**
     * position key => short code, for the POS column.
     *
     * The paper form writes positions as codes (PG, B, T, D) because the column
     * is a thumb wide. The catalogue holds only full labels, and they belong to
     * the admin — /admin/sports renames them freely — so the codes are derived
     * from whatever the labels currently say: initials of each word, so "Bek
     * Sayap" is BS and "Gelandang Serang" is GS.
     *
     * **Any collision abandons the whole map**, and that is the point: two
     * different positions printing the same letter is worse than printing the
     * full label, because the reader cannot tell it happened. The caller falls
     * back to Catalog::positionLabel() per row, so an all-or-nothing map is
     * exactly what it can use. Test it by comparing a sport whose labels
     * collide with one whose labels do not; asserting a code appears would pass
     * against a map that silently merged two positions.
     *
     * @return array<string, string>
     */
    protected function positionShorts(?string $sport): array
    {
        $shorts = [];

        foreach (Catalog::positions($sport) as $position) {
            $code = collect(preg_split('/\s+/', trim((string) $position['label'])))
                ->filter()
                ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
                ->implode('');

            if ($code === '' || in_array($code, $shorts, true)) {
                return [];
            }

            $shorts[$position['key']] = $code;
        }

        return $shorts;
    }

    /**
     * "PG = Penjaga Gawang · B = Bek …", limited to the codes this sheet
     * actually prints.
     *
     * Listing the sport's whole catalogue would put positions on the page that
     * nobody in either squad plays; listing none would leave an abbreviation the
     * printout has no way to expand. Empty when positionShorts() gave up, since
     * the rows then carry full labels already.
     *
     * @param  array<string, string>  $shorts
     * @return array<int, string>
     */
    protected function positionLegend(GameMatch $match, ?string $sport, array $shorts): array
    {
        if (! $shorts) {
            return [];
        }

        $used = collect([$match->homeTeam, $match->awayTeam])
            ->filter()
            ->flatMap(fn (Team $team) => $team->players->pluck('position'))
            ->filter()
            ->unique();

        return $used
            ->filter(fn (string $key) => isset($shorts[$key]))
            ->map(fn (string $key) => $shorts[$key].' = '.Catalog::positionLabel($sport, $key))
            ->values()
            ->all();
    }

    /**
     * The scoreline broken into periods — the small table printed between the
     * two big numbers, home value to the left of each label and away to the
     * right, the way the paper form lays it out.
     *
     * A set-based sport already stores its sets, so they print filled. A
     * goal-based one stores only the final score — there is no half-time column
     * anywhere — so the two babak rows are printed empty and ruled for the
     * panitia to write in. Deriving a half-time score from a full-time one is
     * not possible, and leaving the rows out would make this report say less
     * than the paper form it replaces.
     *
     * **Extra time is a blank row, penalties are a filled one, and the
     * difference is the whole rule of this sheet.** A shootout is stored, so it
     * prints its numbers; extra time is nowhere in the schema, so it prints the
     * ruled box that the form has always had. Test the pair together: asserting
     * the Penalty row carries 4-3 would stay green on a template that filled
     * every row from the same place.
     *
     * @return array<int, array{label: string, home: int|string, away: int|string}>
     */
    protected function periods(GameMatch $match): array
    {
        if ($match->category?->usesRubbers()) {
            return [];
        }

        $sets = $match->sets ?? [];

        $rows = $sets
            ? collect($sets)->values()->map(fn ($set, $i) => [
                'label' => 'Set '.($i + 1),
                'home' => $set['home'] ?? '',
                'away' => $set['away'] ?? '',
            ])->all()
            : [
                ['label' => 'Babak 1', 'home' => '', 'away' => ''],
                ['label' => 'Babak 2', 'home' => '', 'away' => ''],
                ['label' => 'Extra Time', 'home' => '', 'away' => ''],
            ];

        $rows[] = [
            'label' => 'Penalty',
            'home' => $match->home_penalty ?? '',
            'away' => $match->away_penalty ?? '',
        ];

        return $rows;
    }

    /**
     * The partai of a squad tie, when the category is played over them. Empty
     * for everything else — the parent scoreline is the whole result there.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rubbers(GameMatch $match): array
    {
        if (! $match->category?->usesRubbers()) {
            return [];
        }

        return $match->rubbers->sortBy('order')->values()->map(fn ($rubber) => [
            'label' => $rubber->label,
            'home' => $rubber->home_score ?? '',
            'away' => $rubber->away_score ?? '',
            'sets' => collect($rubber->sets ?? [])
                ->map(fn ($set) => ($set['home'] ?? '-').'-'.($set['away'] ?? '-'))
                ->implode(', '),
        ])->all();
    }

    /**
     * The substitution table: ruled and empty, always.
     *
     * Nothing anywhere records who came off for whom, or when. `match_lineups`
     * names who was *available* on the bench, which is a different fact — a
     * named substitute may never come on, and printing the bench here would
     * claim seven substitutions were made in a match that saw none. The same
     * rule the rest of this sheet runs on applies: a cell the database cannot
     * answer is ruled and blank, not invented.
     *
     * Gated on whether the sport names a squad at all — the same gate the team
     * sheet uses, deliberately, so a badminton tie never prints a bench table it
     * has no bench for. The row count is a plain minimum because there is
     * nothing to count.
     *
     * @return int  how many ruled rows to print, 0 to print no table
     */
    protected function substitutions(GameMatch $match, ?string $sport): int
    {
        $rules = $match->category
            ? SquadRules::forCategory($match->category)
            : SquadRules::forSport($sport);

        if (! $rules->enabled) {
            return 0;
        }

        // A bench of three gets three lines plus the minimum's worth of room;
        // the sheet is a form, so the larger of the two wins.
        return max(self::MIN_SUBSTITUTION_ROWS, $rules->maxSubstitutes);
    }

    /**
     * The three incident lists — yellows, reds, scorers — each as a pair of
     * half-width columns.
     *
     * **The roles are read, never the stat keys.** `yellow_cards` is a name an
     * admin owns and may rename from /admin/sports, and a sport calling its card
     * `kartu_kuning` would silently print an empty table forever. This is the
     * same rule DisciplineService runs on, for the same reason, and it is why a
     * sport with no card columns at all (volleyball, basketball) prints no card
     * tables rather than two empty ones.
     *
     * A stat row is an aggregate — `goals: 2` is two goals by one player — so it
     * expands into that many lines, each with its own blank minute box, which is
     * what a scorer who scored twice needs. The expansion is capped: the value
     * is an integer an organizer typed, and a slipped zero would push the sheet
     * off its page.
     *
     * Every table still prints its minimum of ruled rows even when the database
     * has nothing for it: a match with no cards still needs somewhere to write
     * the one the stat editor never received.
     *
     * @param  Collection<string, Collection<string, int>>  $stats
     * @return array<int, array{title: string, home: array<int, array<string, string>>, away: array<int, array<string, string>>, rows: int}>
     */
    protected function incidents(GameMatch $match, ?string $sport, Collection $stats): array
    {
        $lists = [];

        foreach ([['yellow', 'Daftar Kartu Kuning'], ['red', 'Daftar Kartu Merah'], ['goal', 'Daftar Pencetak Gol']] as [$role, $title]) {
            $key = Catalog::statKeyForRole($sport, $role);

            if (! $key) {
                continue;
            }

            $home = $this->incidentRows($match->homeTeam, $key, $stats);
            $away = $this->incidentRows($match->awayTeam, $key, $stats);

            $lists[] = [
                'title' => $title,
                'home' => $home,
                'away' => $away,
                'rows' => max(self::MIN_LIST_ROWS, count($home), count($away)),
            ];
        }

        return $lists;
    }

    /**
     * One side of one incident list: a line per occurrence, in shirt order.
     *
     * Read off the team's own roster rather than off the stat rows, so a stat
     * belonging to a player who has since been removed from the squad cannot
     * put a nameless line on the sheet.
     *
     * @param  Collection<string, Collection<string, int>>  $stats
     * @return array<int, array{name: string, number: string}>
     */
    protected function incidentRows(?Team $team, string $key, Collection $stats): array
    {
        if (! $team) {
            return [];
        }

        $rows = [];

        foreach ($team->players as $player) {
            $count = (int) ($stats[$player->id][$key] ?? 0);

            for ($i = 0; $i < min($count, self::MAX_ROWS_PER_PLAYER); $i++) {
                $rows[] = [
                    'name' => $player->full_name,
                    'number' => (string) ($player->jersey_number ?: ''),
                ];
            }
        }

        return $rows;
    }

    /** "Grup A" / "Babak 3" / null — whichever the fixture actually carries. */
    protected function phase(GameMatch $match): ?string
    {
        if ($match->group_name) {
            return 'Grup '.$match->group_name;
        }

        return $match->round ? 'Babak '.$match->round : null;
    }
}
