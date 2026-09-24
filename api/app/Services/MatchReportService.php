<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamOfficial;
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
            'dateLabel' => $kickoff?->locale('id')->translatedFormat('l, d F Y') ?? 'Belum dijadwalkan',
            // The zone's own abbreviation, not a hardcoded "WIB": an event in
            // Makassar prints WITA, which is what the paper form carries too.
            'kickoffLabel' => $kickoff ? $kickoff->format('H:i').' '.$kickoff->format('T') : null,
            'venue' => $match->venue ?: $event->location_name,
            'durationLabel' => ($minutes = Catalog::sport($sport)['default_match_minutes'] ?? null)
                ? $minutes.' menit'
                : null,
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

    /** "Grup A" / "Babak 3" / null — whichever the fixture actually carries. */
    protected function phase(GameMatch $match): ?string
    {
        if ($match->group_name) {
            return 'Grup '.$match->group_name;
        }

        return $match->round ? 'Babak '.$match->round : null;
    }
}
