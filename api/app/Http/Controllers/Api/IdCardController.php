<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdCard\GenerateIdCardsRequest;
use App\Jobs\GenerateIdCardsJob;
use App\Models\Event;
use App\Models\Organization;
use App\Services\IdCardService;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdCardController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected IdCardService $cards,
    ) {}

    /** Everyone in this event who can be handed a card. */
    public function recipients(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->findEvent($request, $event);

        return ApiResponse::success($this->cards->recipients($model));
    }

    /**
     * Queue a batch. 202 rather than 201: nothing has been rendered yet, and the
     * client is being handed an id to poll, not a resource.
     */
    public function generate(GenerateIdCardsRequest $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);

        // findEvent first: the entitlement belongs to the event, so there is
        // nothing to ask until we know which event this is.
        $model = $this->findEvent($request, $event);

        if (! $this->gate->allows($model, 'id_card_generator')) {
            return ApiResponse::error(
                'Generator ID card tidak tersedia di paket event ini.',
                ['feature' => 'id_card_generator'],
                403,
            );
        }

        $data = $request->validated();

        $template = $org->idCardTemplates()
            ->where('id', $data['id_card_template_id'])
            ->firstOrFail();

        // Resolved against the event's own pool rather than trusted from the
        // request: the ids arrive from the client, and rendering whatever they
        // name would print cards for another organizer's roster.
        $wanted = collect($data['recipients'])
            ->map(fn (array $r) => $r['type'].':'.$r['id'])
            ->all();

        $recipients = collect($this->cards->recipients($model))
            ->filter(fn (array $person) => in_array($person['type'].':'.$person['id'], $wanted, true))
            ->values()
            ->all();

        if ($recipients === []) {
            return ApiResponse::error('Tidak ada penerima yang cocok di event ini.', null, 422);
        }

        $batchId = (string) Str::uuid();

        Cache::put(GenerateIdCardsJob::cacheKey($batchId), [
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
        ], now()->addHours(GenerateIdCardsJob::TTL_HOURS));

        GenerateIdCardsJob::dispatch($batchId, $model->id, $template->id, $recipients);

        return ApiResponse::success(
            ['batch_id' => $batchId, 'total' => count($recipients)],
            count($recipients).' kartu sedang dibuat.',
            202,
        );
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
            return ApiResponse::error('Kartu belum selesai dibuat.', null, 409);
        }

        // Through the service, not Storage::disk('r2') directly: it is the half
        // that wrote the object and the only thing that knows which disk that
        // was. Two readers of the same rule would drift, and the drift here is
        // a 404 on a file that exists.
        return $this->cards->storage()->download($payload['key'], $payload['filename'] ?? 'id-cards.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * The batch entry, but only if it belongs to the calling organization.
     *
     * Neither read is gated on the plan, for the same reason
     * CertificateController::download() is not: refusing to hand over a file
     * that was already rendered under a valid entitlement helps nobody. What it
     * *is* checked for is ownership — a batch id is a UUID in a shared cache,
     * and without this comparison holding one would be enough to read another
     * organization's roster photos.
     *
     * A 404 (not 403) for a foreign batch: whether an id exists is itself not
     * something a stranger should learn.
     *
     * @return array<string, mixed>|null
     */
    protected function ensureOwned(Request $request, string $batch): ?array
    {
        $payload = Cache::get(GenerateIdCardsJob::cacheKey($batch));

        if (! is_array($payload)) {
            return null;
        }

        return ($payload['organization_id'] ?? null) === $this->org($request)->id ? $payload : null;
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
