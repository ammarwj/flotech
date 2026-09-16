<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Organization;
use App\Services\Catalog;
use App\Support\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The team sheet that goes on the IP table, as a PDF.
 *
 * Two routes, one action. Staff reach it through `officiating/events/{event}/…`
 * and the organizer through `organizations/{organization}/…`, because the panitia
 * has to be able to print it too — but the gate below is written once. A second
 * copy of "both sides approved" is how one door starts printing sheets the
 * referee never signed.
 *
 * That gate is the most literal part of what was asked for: *"di acc wasit
 * kemudian baru bisa di print meja IP"*. It refuses on **both** sheets, not the
 * one being looked at — a sheet is a fixture's document, and half of one is not a
 * lighter version of it.
 */
class LineupSheetController extends Controller
{
    /**
     * @param  string  $scope  `{event}` on the crew's route, `{organization}` on
     *                         the organizer's. Never read: both were already
     *                         resolved by the middleware that put the event or
     *                         the organization on the request, and re-reading the
     *                         segment is how a controller starts trusting a URL
     *                         over the door it came through.
     */
    public function download(Request $request, string $scope, string $match): Response
    {
        $model = $this->match($request, $match);

        if (! $model->home_team_id || ! $model->away_team_id) {
            return ApiResponse::error(
                'Pertandingan ini belum punya kedua tim.',
                null,
                422,
            );
        }

        $sheets = MatchLineup::where('match_id', $model->id)
            ->with(['players.player', 'officials.official'])
            ->get()
            ->keyBy('team_id');

        $home = $sheets->get($model->home_team_id);
        $away = $sheets->get($model->away_team_id);

        if ($home?->status !== 'approved' || $away?->status !== 'approved') {
            return ApiResponse::error(
                'Susunan pemain kedua tim harus disetujui wasit dulu.',
                null,
                422,
            );
        }

        $event = $model->event;
        $tz = $event->timezone;

        // Blade runs the child section before the layout, so anything the
        // template needs to format with arrives as view data — the same trap
        // BillingDocumentService already has a comment about.
        return Pdf::loadView('pdf.lineup-sheet', [
            'event' => $event,
            'category' => $model->category,
            'match' => $model,
            'phase' => $this->phase($model),
            'dateLabel' => $model->scheduled_at
                ? Carbon::parse($model->scheduled_at)->timezone($tz)->locale('id')->translatedFormat('l, d F Y')
                : 'Belum dijadwalkan',
            'timeLabel' => $model->scheduled_at
                ? Carbon::parse($model->scheduled_at)->timezone($tz)->format('H:i')
                : null,
            'printedAt' => Carbon::now($tz)->locale('id')->translatedFormat('d F Y, H:i'),
            'home' => $this->side($model->homeTeam?->name, $home, $event->sport_type),
            'away' => $this->side($model->awayTeam?->name, $away, $event->sport_type),
        ])->download($this->fileName($model).'.pdf');
    }

    /**
     * One side of the sheet, flattened to what the template prints.
     *
     * Starters and substitutes are split here rather than in Blade: the template
     * is a first pass the user intends to replace, and a `@if` on `role` inside
     * two nested loops is exactly the part that would not survive a rewrite.
     *
     * @return array<string, mixed>
     */
    protected function side(?string $teamName, MatchLineup $lineup, ?string $sport): array
    {
        $rows = fn (string $role) => $lineup->players
            ->where('role', $role)
            ->map(fn ($row) => [
                'number' => $row->player?->jersey_number,
                'name' => $row->player?->full_name ?? '—',
                'position' => $row->player?->position,
            ])
            ->values();

        return [
            'team' => $teamName ?? '—',
            'starters' => $rows('starter'),
            'substitutes' => $rows('substitute'),
            'officials' => $lineup->officials->map(fn ($row) => [
                'name' => $row->official?->full_name ?? '—',
                // Catalog returns null for a role the sport has no catalogue
                // for, or one an admin renamed away — the fallback is this
                // caller's to pick, and a blank column would read as a bug.
                'role' => Catalog::officialRoleLabel($sport, $row->official?->role) ?? 'Ofisial',
            ])->values(),
        ];
    }

    /** "Grup A" / "Babak 3" / null — whichever the fixture actually carries. */
    protected function phase(GameMatch $match): ?string
    {
        if ($match->group_name) {
            return 'Grup '.$match->group_name;
        }

        return $match->round ? 'Babak '.$match->round : null;
    }

    protected function fileName(GameMatch $match): string
    {
        return 'susunan-pemain-'.Str::slug(
            ($match->homeTeam?->name ?? 'home').' vs '.($match->awayTeam?->name ?? 'away'),
        );
    }

    /**
     * The fixture, resolved through whichever door this request came in by.
     *
     * The crew's route carries an `event` attribute and no `organization` one —
     * deliberately, and it must stay that way, so this branch reads the event and
     * never reaches for a tenant. The organizer's route carries the organization
     * and scopes through its events, exactly as MatchController does.
     */
    protected function match(Request $request, string $matchId): GameMatch
    {
        $relations = ['event', 'category', 'homeTeam', 'awayTeam'];

        /** @var Event|null $event */
        $event = $request->attributes->get('event');

        if ($event) {
            return $event->matches()->with($relations)->findOrFail($matchId);
        }

        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return GameMatch::with($relations)
            ->whereHas('event', fn ($q) => $q->where('organization_id', $org->id))
            ->findOrFail($matchId);
    }
}
