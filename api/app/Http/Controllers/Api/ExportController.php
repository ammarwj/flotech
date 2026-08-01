<?php

namespace App\Http\Controllers\Api;

use App\Exports\EventExport;
use App\Exports\LeaderboardExport;
use App\Exports\RegistrationsExport;
use App\Exports\StandingsExport;
use App\Exports\TicketBuyersExport;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Services\PlayerStatService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads of an event's data as a spreadsheet or a PDF.
 *
 * The `export_data` entitlement is checked on line one of every action, on the
 * *event* — an organizer can be running a Pro event and a Starter one, and only
 * the first may be exported. This is the whole reason the endpoint exists rather
 * than the client building a file out of list responses it already has: a gate
 * on `/registrations` would break the page that reads it, so the only place
 * `export_data` can mean anything is a route whose sole purpose is the download.
 *
 * Behind `org.admin`, like the rest of the money-and-data surface: an operator
 * scans tickets, they do not take the entrant list home.
 */
class ExportController extends Controller
{
    /** Exports scoped to a whole event. */
    private const EVENT_KINDS = ['registrations', 'ticket-buyers'];

    /** Exports scoped to one category, so they need `?category_id=`. */
    private const CATEGORY_KINDS = ['standings', 'leaderboard'];

    public function __construct(
        protected PlanGate $gate,
        protected StandingService $standings,
        protected PlayerStatService $stats,
    ) {}

    public function show(Request $request, string $organization, string $event, string $kind): Response
    {
        $model = $this->event($request, $event);

        if (! $this->gate->allows($model, 'export_data')) {
            return ApiResponse::error(
                'Export data tidak tersedia di paket event ini.',
                ['feature' => 'export_data'],
                403,
            );
        }

        $data = $request->validate([
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
            'category_id' => ['nullable', 'uuid'],
        ]);

        if (! in_array($kind, [...self::EVENT_KINDS, ...self::CATEGORY_KINDS], true)) {
            return ApiResponse::error('Jenis export tidak dikenal.', null, 404);
        }

        $export = $this->exportFor($model, $kind, $data['category_id'] ?? null);

        if (! $export) {
            return ApiResponse::error(
                'Export ini butuh kategori. Sertakan category_id.',
                ['category_id' => ['Kategori wajib dipilih.']],
                422,
            );
        }

        $name = $model->slug.'-'.$export->slug();

        if (($data['format'] ?? 'xlsx') === 'pdf') {
            return Pdf::loadView('pdf.export', [
                'title' => $export->title(),
                'event' => $model,
                'headings' => $export->headings(),
                'rows' => $export->rows(),
            ])->download($name.'.pdf');
        }

        return Excel::download($export, $name.'.xlsx');
    }

    /** Null when a category-scoped export was asked for without a category. */
    protected function exportFor(Event $event, string $kind, ?string $categoryId): ?EventExport
    {
        if (in_array($kind, self::CATEGORY_KINDS, true)) {
            if (! $categoryId) {
                return null;
            }

            /** @var EventCategory $category */
            $category = $event->categories()->findOrFail($categoryId);

            return $kind === 'standings'
                ? new StandingsExport($category, $this->standings)
                : new LeaderboardExport($category, $this->stats);
        }

        return $kind === 'registrations'
            ? new RegistrationsExport($event)
            : new TicketBuyersExport($event);
    }

    protected function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->with('plan.features')->findOrFail($eventId);
    }
}
