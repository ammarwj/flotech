<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Certificate\GenerateCertificatesRequest;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificateZipJob;
use App\Jobs\SendCertificateJob;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Services\CertificateService;
use App\Services\PlanGate;
use App\Services\R2StorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected CertificateService $certificates,
        protected R2StorageService $r2,
    ) {}

    /**
     * Issued certificates, newest first; optionally narrowed to one event.
     *
     * Paginated, like the check-in log and the buyer list: this is the one
     * payload here that grows without bound. An organizer handing a certificate
     * to every runner in a fun run has thousands of rows, and the unpaginated
     * version sent all of them on every visit to the page.
     *
     * `total` is deliberately the count over the whole filter rather than the
     * rows on screen — the bulk-zip button reads it to decide whether there is
     * anything to pack, and that question is about the filter, not the page.
     */
    public function index(Request $request, string $organization): JsonResponse
    {
        $filters = $request->validate([
            'event_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->org($request)
            ->certificates()
            ->with('event:id,name')
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->latest('issued_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return ApiResponse::success([
            'items' => CertificateResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Who can still be handed a certificate for this event. */
    public function recipients(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->findEvent($request, $event);
        $pool = $this->certificates->recipients($model);

        return ApiResponse::success([
            'teams' => $pool['teams']->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'email' => $team->manager?->email,
                'players_count' => $team->players->count(),
            ])->values(),
            'players' => $pool['players']->map(fn ($player) => [
                'id' => $player->id,
                'name' => $player->full_name,
                'team_id' => $player->team_id,
                'jersey_number' => $player->jersey_number,
            ])->values(),
        ]);
    }

    /** Issue + render a batch of certificates, optionally emailing them. */
    public function generate(GenerateCertificatesRequest $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);

        // findEvent first: the entitlement belongs to the event, so there is
        // nothing to ask until we know which event this is.
        $model = $this->findEvent($request, $event);

        if ($denied = $this->ensureEnabled($model)) {
            return $denied;
        }

        $data = $request->validated();

        $sendEmail = (bool) ($data['send_email'] ?? false);

        if ($sendEmail && ! $this->gate->allows($model, 'certificate_email')) {
            return ApiResponse::error(
                'Pengiriman sertifikat via email tidak tersedia di paketmu.',
                ['feature' => 'certificate_email'],
                403,
            );
        }

        $template = $org->certificateTemplates()
            ->where('id', $data['certificate_template_id'])
            ->firstOrFail();

        $issued = $this->certificates->issueBatch(
            $model,
            $template,
            $data['recipients'],
            $data['award_title'],
        );

        if ($sendEmail) {
            $issued->each(fn (Certificate $certificate) => SendCertificateJob::dispatch($certificate));
        }

        $skipped = count($data['recipients']) - $issued->count();

        $message = $issued->count().' sertifikat diterbitkan.'
            .($skipped > 0 ? " {$skipped} dilewati (sudah punya penghargaan ini)." : '');

        return ApiResponse::success(
            CertificateResource::collection($issued),
            $message,
            201,
        );
    }

    /** Queue the email for one already-issued certificate. */
    public function send(Request $request, string $organization, string $certificate): JsonResponse
    {
        $org = $this->org($request);
        $model = $this->find($org, $certificate);

        if (! $this->gate->allows($model->event, 'certificate_email')) {
            return ApiResponse::error(
                'Pengiriman sertifikat via email tidak tersedia di paket event ini.',
                ['feature' => 'certificate_email'],
                403,
            );
        }

        if (! $model->recipient_email) {
            return ApiResponse::error('Penerima ini tidak punya alamat email.', null, 422);
        }

        SendCertificateJob::dispatch($model);

        return ApiResponse::success(null, 'Sertifikat sedang dikirim ke '.$model->recipient_email);
    }

    /** Stream the rendered PDF. The bucket key never leaves the server. */
    public function download(Request $request, string $organization, string $certificate): StreamedResponse|JsonResponse
    {
        $model = $this->find($this->org($request), $certificate);

        if (! $model->pdf_key) {
            return ApiResponse::error('PDF sertifikat belum tersedia.', null, 404);
        }

        $filename = str_replace('/', '-', $model->certificate_number).'.pdf';

        return $this->r2->disk()->download($model->pdf_key, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Queue a bulk zip of every issued certificate, optionally narrowed to one
     * event. 202 rather than 201: nothing has been packed yet, and the client is
     * being handed an id to poll, not a resource.
     *
     * Deliberately ungated on the plan, exactly like download() below and for
     * the same reason — this *is* download(), repeated. It is also what lets
     * "semua event" be answered without a second rule: with no event in hand
     * there is no plan to ask, so a gate here would need an aggregate rule of
     * its own and the two would drift.
     */
    public function zip(Request $request, string $organization): JsonResponse
    {
        $org = $this->org($request);

        $data = $request->validate([
            'event_id' => ['nullable', 'uuid'],
        ]);

        $event = isset($data['event_id'])
            ? $org->events()->where('id', $data['event_id'])->firstOrFail()
            : null;

        // Resolved from the organization's own rows rather than from a list of
        // ids in the request: the same reasoning as IdCardController::generate(),
        // and here it is the whole ownership story — zipping whatever the client
        // named would hand over another organizer's documents.
        $certificates = $org->certificates()
            ->when($event, fn ($q) => $q->where('event_id', $event->id))
            // A row still waiting on its PDF has nothing to contribute. Filtered
            // here rather than inside zip() so `total` is the number of files the
            // organizer will actually get, not the number of rows on screen.
            ->whereNotNull('pdf_key')
            ->latest('issued_at')
            ->limit((int) config('certificate.max_batch'))
            ->pluck('id')
            ->all();

        if ($certificates === []) {
            return ApiResponse::error('Belum ada sertifikat yang bisa diunduh.', null, 422);
        }

        $batchId = (string) Str::uuid();

        Cache::put(GenerateCertificateZipJob::cacheKey($batchId), [
            'status' => 'queued',
            // The ownership check every later read makes. Without it the batch
            // id is a bearer token for anyone who can guess one.
            'organization_id' => $org->id,
            'event_id' => $event?->id,
            'total' => count($certificates),
            'done' => 0,
            'key' => null,
            'filename' => null,
            'error' => null,
        ], now()->addHours(GenerateCertificateZipJob::TTL_HOURS));

        GenerateCertificateZipJob::dispatch($batchId, $certificates, $event?->id);

        return ApiResponse::success(
            ['batch_id' => $batchId, 'total' => count($certificates)],
            count($certificates).' sertifikat sedang dikemas.',
            202,
        );
    }

    /** Poll target. Deliberately ungated — see ensureOwnedBatch(). */
    public function zipStatus(Request $request, string $organization, string $batch): JsonResponse
    {
        $payload = $this->ensureOwnedBatch($request, $batch);

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
    public function zipDownload(Request $request, string $organization, string $batch): StreamedResponse|JsonResponse
    {
        $payload = $this->ensureOwnedBatch($request, $batch);

        if ($payload === null) {
            return ApiResponse::error('Batch tidak ditemukan atau sudah kedaluwarsa.', null, 404);
        }

        if (($payload['status'] ?? null) !== 'done' || ! ($payload['key'] ?? null)) {
            return ApiResponse::error('Zip belum selesai dibuat.', null, 409);
        }

        // Through the service, not Storage::disk('r2') directly: it is the half
        // that wrote the object and the only thing that knows which disk that
        // was. Two readers of the same rule would drift, and the drift here is a
        // 404 on a file that exists.
        return $this->certificates->storage()->download(
            $payload['key'],
            $payload['filename'] ?? 'sertifikat.zip',
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * The batch entry, but only if it belongs to the calling organization.
     *
     * Not gated on the plan, for the same reason download() is not. What it *is*
     * checked for is ownership — a batch id is a UUID in a shared cache, and
     * without this comparison holding one would be enough to read another
     * organization's certificates.
     *
     * A 404 (not 403) for a foreign batch: whether an id exists is itself not
     * something a stranger should learn. Twin of IdCardController::ensureOwned().
     *
     * @return array<string, mixed>|null
     */
    protected function ensureOwnedBatch(Request $request, string $batch): ?array
    {
        $payload = Cache::get(GenerateCertificateZipJob::cacheKey($batch));

        if (! is_array($payload)) {
            return null;
        }

        return ($payload['organization_id'] ?? null) === $this->org($request)->id ? $payload : null;
    }

    public function destroy(Request $request, string $organization, string $certificate): JsonResponse
    {
        $model = $this->find($this->org($request), $certificate);

        if ($model->pdf_key) {
            $this->r2->delete($model->pdf_key);
        }

        $model->delete();

        return ApiResponse::success(null, 'Sertifikat dihapus');
    }

    /**
     * Gates issuing only. `download()` and `destroy()` are deliberately left
     * open: a certificate legitimately issued under a paid event must stay
     * downloadable and deletable forever, and blocking it later would punish a
     * purchase that was already honoured. Same reasoning as
     * EventMediaController::updateSponsor().
     */
    protected function ensureEnabled(Event $event): ?JsonResponse
    {
        if (! $this->gate->allows($event, 'certificate_generator')) {
            return ApiResponse::error(
                'Generator sertifikat tidak tersedia di paket event ini.',
                ['feature' => 'certificate_generator'],
                403,
            );
        }

        return null;
    }

    protected function find(Organization $org, string $id): Certificate
    {
        return $org->certificates()->where('id', $id)->firstOrFail();
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
