<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bib\GenerateBibsRequest;
use App\Jobs\GenerateBibsJob;
use App\Jobs\SendBibsJob;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Services\BibService;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printing a race's e-BIBs. Twin of IdCardController, with two differences that
 * are both decisions rather than omissions:
 *
 *   Gated TWICE, and the two gates ask different questions —
 *     `bib_generator` is the plan entitlement, and `scoringOf() === 'none'` is
 *     whether this event is a race at all. A football event has no runners, so
 *     offering e-BIB there announces a door that does not exist.
 *   No issued-rows index. There is no `bibs` table: a printed bib's row is the
 *     `race_entries` row it was printed from, so the print history is the start
 *     list, which RaceEntryController already serves.
 */
class BibController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected BibService $bibs,
    ) {}

    /** Every paid runner in this event who can be handed a bib. */
    public function recipients(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->findEvent($request, $event);

        if ($denied = $this->ensureRaceEvent($model)) {
            return $denied;
        }

        return ApiResponse::success($this->bibs->recipients($model));
    }

    /**
     * Queue a batch. 202 rather than 201: nothing has been rendered yet, and
     * the client is being handed an id to poll, not a resource.
     *
     * Numbers are assigned here, before the job is dispatched — see
     * BibService::assignNumbersForEvent() for why that is not a separate
     * button. It happens inside the request rather than in the job so the
     * response can tell the organizer how many bibs are new, and so a queue
     * that never drains still leaves the start list numbered.
     */
    public function generate(GenerateBibsRequest $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);

        // findEvent first: the entitlement belongs to the event, so there is
        // nothing to ask until we know which event this is.
        $model = $this->findEvent($request, $event);

        if ($denied = $this->ensureEnabled($model)) {
            return $denied;
        }

        if ($denied = $this->ensureRaceEvent($model)) {
            return $denied;
        }

        $data = $request->validated();

        $template = $org->bibTemplates()
            ->where('id', $data['bib_template_id'])
            ->firstOrFail();

        $assigned = $this->bibs->assignNumbersForEvent($model);

        // Resolved against the event's own pool rather than trusted from the
        // request: the ids arrive from the client, and rendering whatever they
        // name would print sheets carrying another organizer's runners — and
        // their check-in credentials.
        $wanted = $data['entry_ids'];

        $recipients = collect($this->bibs->recipients($model))
            ->filter(fn (array $person) => in_array($person['id'], $wanted, true))
            ->values()
            ->all();

        if ($recipients === []) {
            return ApiResponse::error(
                'Tidak ada pelari lunas yang cocok di event ini.',
                null,
                422,
            );
        }

        $batchId = (string) Str::uuid();

        Cache::put(GenerateBibsJob::cacheKey($batchId), [
            'status' => 'queued',
            // The ownership check every later read makes. Without it the batch
            // id is a bearer token for anyone who can guess one.
            'organization_id' => $org->id,
            'event_id' => $model->id,
            'total' => count($recipients),
            'done' => 0,
            'key' => null,
            'filename' => null,
            'error' => null,
        ], now()->addHours(GenerateBibsJob::TTL_HOURS));

        GenerateBibsJob::dispatch($batchId, $model->id, $template->id, $recipients);

        return ApiResponse::success(
            ['batch_id' => $batchId, 'total' => count($recipients), 'numbered' => $assigned],
            count($recipients).' e-BIB sedang dibuat.'
                // Stated, never swallowed: the organizer is about to read a
                // start list that changed under them, and "12 nomor baru
                // diberikan" is the only thing on screen that explains it.
                .($assigned > 0 ? " {$assigned} nomor BIB baru diberikan." : ''),
            // 202, not 200: nothing has been rendered yet, and the client is
            // being handed an id to poll rather than a resource.
            202,
        );
    }

    /**
     * Email this race's e-BIBs to the runners they belong to.
     *
     * Gated event-keyed, not through orgAllows(): what is being sent is a
     * particular race's bibs, so the entitlement that matters is that event's.
     * Templates are the org-level question, and that one lives in
     * BibTemplateController.
     *
     * No separate `bib_email` feature key, the same call the ID card feature
     * made: only Professional carries `bib_generator` today, so a second key
     * would separate no two plans in the catalogue — it would just be another
     * row to keep aligned across PlanSeeder and FeatureDefinitionSeeder.
     *
     * There is no grouping step, unlike IdCardController::send(). A runner
     * holds their own address, so one entry is one mail — see SendBibsJob.
     */
    public function send(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->findEvent($request, $event);

        if ($denied = $this->ensureEnabled($model)) {
            return $denied;
        }

        if ($denied = $this->ensureRaceEvent($model)) {
            return $denied;
        }

        $filters = $request->validate([
            // Absent means every rendered bib for this event. An explicit list
            // is what a "kirim ulang ke orang ini" button sends.
            'entry_ids' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('bib.max_recipients')],
            'entry_ids.*' => ['uuid'],
        ]);

        $entries = $model->raceEntries()
            ->whereNotNull('bib_png_key')
            ->when(
                $filters['entry_ids'] ?? null,
                fn ($q, array $ids) => $q->whereIn('id', $ids),
            )
            ->get(['id', 'email']);

        if ($entries->isEmpty()) {
            return ApiResponse::error('Belum ada e-BIB yang dibuat untuk event ini.', null, 422);
        }

        // An entry with no address is skipped and REPORTED, never dropped
        // silently: `race_entries.email` is required at registration, so this
        // is rare — but "40 e-BIB dikirim" printed over 55 runners reads as a
        // bug rather than a fact, and the organizer is the only one who can
        // chase the gap.
        $sendable = $entries->filter(fn (RaceEntry $entry) => (bool) $entry->email);

        if ($sendable->isEmpty()) {
            return ApiResponse::error(
                'Tidak ada e-BIB yang punya alamat email tujuan.',
                null,
                422,
            );
        }

        foreach ($sendable as $entry) {
            SendBibsJob::dispatch($entry->id);
        }

        $skipped = $entries->count() - $sendable->count();

        return ApiResponse::success(
            ['emails' => $sendable->count(), 'skipped' => $skipped],
            $sendable->count().' e-BIB dikirim via email.'
                .($skipped > 0 ? " {$skipped} dilewati (tanpa alamat email)." : ''),
        );
    }

    /**
     * Stream one runner's sheet. The bucket key never leaves the server.
     *
     * Deliberately ungated on the plan, exactly like
     * CertificateController::download() and IdCardController::downloadCard(): a
     * sheet rendered under an entitlement that was paid for must stay
     * reachable, and refusing to hand over a file that already exists helps
     * nobody.
     */
    public function downloadEntry(Request $request, string $organization, string $event, string $entry): StreamedResponse|JsonResponse
    {
        $model = $this->findEvent($request, $event);

        /** @var RaceEntry $row */
        $row = $model->raceEntries()->where('id', $entry)->firstOrFail();

        if (! $row->bib_png_key) {
            return ApiResponse::error('Berkas e-BIB belum tersedia.', null, 404);
        }

        return $this->bibs->storage()->download($row->bib_png_key, $this->bibs->filename($row), [
            'Content-Type' => 'image/png',
        ]);
    }

    /** Poll target. Deliberately ungated — see ensureOwned(). */
    public function status(Request $request, string $organization, string $batch): JsonResponse
    {
        $payload = $this->ensureOwned($request, $batch);

        if ($payload === null) {
            return ApiResponse::error('Batch tidak ditemukan atau sudah kedaluwarsa.', null, 404);
        }

        return ApiResponse::success([
            'batch_id' => $batch,
            'status' => $payload['status'] ?? 'queued',
            'total' => (int) ($payload['total'] ?? 0),
            'done' => (int) ($payload['done'] ?? 0),
            'filename' => $payload['filename'] ?? null,
            'error' => $payload['error'] ?? null,
        ]);
    }

    /** Stream the finished zip. The bucket key never leaves the server. */
    public function download(Request $request, string $organization, string $batch): StreamedResponse|JsonResponse
    {
        $payload = $this->ensureOwned($request, $batch);

        if ($payload === null) {
            return ApiResponse::error('Batch tidak ditemukan atau sudah kedaluwarsa.', null, 404);
        }

        if (($payload['status'] ?? null) !== 'done' || ! ($payload['key'] ?? null)) {
            return ApiResponse::error('e-BIB belum selesai dibuat.', null, 409);
        }

        // Through the service, not Storage::disk('r2') directly: it is the half
        // that wrote the object and the only thing that knows which disk that
        // was. Two readers of the same rule would drift, and the drift here is
        // a 404 on a file that exists.
        return $this->bibs->storage()->download($payload['key'], $payload['filename'] ?? 'e-bib.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * The batch entry, but only if it belongs to the calling organization.
     *
     * Neither read is gated on the plan, for the same reason
     * CertificateController::download() is not. What it *is* checked for is
     * ownership — a batch id is a UUID in a shared cache, and without this
     * comparison holding one would be enough to read another organization's
     * start list, QR codes included.
     *
     * A 404 (not 403) for a foreign batch: whether an id exists is itself not
     * something a stranger should learn.
     *
     * @return array<string, mixed>|null
     */
    protected function ensureOwned(Request $request, string $batch): ?array
    {
        $payload = Cache::get(GenerateBibsJob::cacheKey($batch));

        if (! is_array($payload)) {
            return null;
        }

        return ($payload['organization_id'] ?? null) === $this->org($request)->id ? $payload : null;
    }

    /** The plan question, event-keyed: these are a particular race's bibs. */
    protected function ensureEnabled(Event $event): ?JsonResponse
    {
        if (! $this->gate->allows($event, 'bib_generator')) {
            return ApiResponse::error(
                'Generator e-BIB tidak tersedia di paket event ini.',
                ['feature' => 'bib_generator'],
                403,
            );
        }

        return null;
    }

    /**
     * The other question: does this event have runners at all?
     *
     * Same shape as RaceCategoryController::ensureRaceEvent(), and read from
     * the catalogue rather than from a column, so a sport an admin adds later
     * falls on the right side without a deploy. Separate from the plan gate on
     * purpose — an organizer who sees "paket tidak mendukung" on a football
     * event would go and buy a plan that changes nothing.
     */
    protected function ensureRaceEvent(Event $event): ?JsonResponse
    {
        if (Catalog::scoringOf($event->sport_type) !== 'none') {
            return ApiResponse::error(
                'e-BIB hanya untuk event cabang tanpa pertandingan.',
                ['feature' => 'bib_generator'],
                403,
            );
        }

        return null;
    }

    protected function findEvent(Request $request, string $id): Event
    {
        return $this->org($request)->events()->where('id', $id)->firstOrFail();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
