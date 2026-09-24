<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Services\MatchReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The post-match report, as a PDF.
 *
 * Two routes, one action — the same shape as LineupSheetController, and for the
 * same reason: the match staff print it at the table and the panitia print it
 * from the dashboard, and a second copy of the gate is how one of the two doors
 * starts handing out reports for matches that never finished.
 *
 * The gate is what was asked for — *"setelah pertandingan selesai itu bisa
 * download laporan pertandingan"* — and it is `isFinished()`, not `status`
 * alone: a fixture marked finished with no scoreline on it has nothing to
 * report, and a report of it would be a blank claiming to be a result. A
 * rubber tie is the one exception to needing a typed scoreline, and it does not
 * need one — its parent score is rolled up from its partai, so by the time it
 * is finished both numbers are there.
 *
 * Confirmation is deliberately **not** part of the gate. Ratifying a result is
 * what makes it count towards the standings; printing the sheet is what the
 * panitia do on their way to deciding whether to ratify it. The report says on
 * its face which of the two it is.
 */
class MatchReportController extends Controller
{
    public function __construct(protected MatchReportService $reports) {}

    /**
     * @param  string  $scope  `{event}` on the crew's route, `{organization}` on
     *                         the organizer's. Never read: both were already
     *                         resolved by the middleware that put the event or
     *                         the organization on the request, and re-reading
     *                         the segment is how a controller starts trusting a
     *                         URL over the door it came through.
     */
    public function download(Request $request, string $scope, string $match): Response
    {
        $model = $this->match($request, $match);

        if (! $model->home_team_id || ! $model->away_team_id) {
            return ApiResponse::error('Pertandingan ini belum punya kedua tim.', null, 422);
        }

        if (! $model->isFinished()) {
            return ApiResponse::error(
                'Laporan baru bisa diunduh setelah pertandingan selesai dan skornya terisi.',
                null,
                422,
            );
        }

        return $this->reports
            ->build($model)
            ->download($this->fileName($model).'.pdf');
    }

    protected function fileName(GameMatch $match): string
    {
        return 'laporan-'.Str::slug(
            ($match->homeTeam?->name ?? 'home').' vs '.($match->awayTeam?->name ?? 'away'),
        );
    }

    /**
     * The fixture, resolved through whichever door this request came in by.
     *
     * Copied in shape from LineupSheetController::match(), including why: the
     * crew's route carries an `event` attribute and no `organization` one, so
     * that branch reads the event and never reaches for a tenant.
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
