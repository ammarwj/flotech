<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdCard\GenerateIdCardsRequest;
use App\Http\Resources\IdCardResource;
use App\Jobs\GenerateIdCardsJob;
use App\Jobs\SendIdCardsJob;
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
     * Cards already issued, newest first; optionally narrowed to one event.
     *
     * Paginated for the same reason CertificateController::index() is: an
     * organizer who printed a card for every player across a season has
     * thousands of rows, and `total` counts the whole filter rather than the
     * page because the send button reads it to know whether there is anything
     * to send.
     */
    public function index(Request $request, string $organization): JsonResponse
    {
        $filters = $request->validate([
            'event_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->org($request)
            ->idCards()
            ->with('event:id,name')
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->latest('issued_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return ApiResponse::success([
            'items' => IdCardResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
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

    /**
     * Email the cards of one event to the people they belong to.
     *
     * Gated event-keyed, not through orgAllows(): what is being sent is a
     * particular event's cards, so the entitlement that matters is that event's.
     * Templates are the org-level question, and that one lives in
     * IdCardTemplateController.
     *
     * No separate `id_card_email` feature key, unlike certificates. Only
     * Professional carries `id_card_generator` today, so a second key would
     * separate no two plans in the catalogue — it would just be another row to
     * keep aligned across PlanSeeder and FeatureDefinitionSeeder.
     *
     * The grouping is the service's, not this method's: three recipient pools
     * answer "whose address?" two different ways, and re-deriving it here would
     * be a second answer that could drift from the column the renderer wrote.
     */
    public function send(Request $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);
        $model = $this->findEvent($request, $event);

        if (! $this->gate->allows($model, 'id_card_generator')) {
            return ApiResponse::error(
                'Generator ID card tidak tersedia di paket event ini.',
                ['feature' => 'id_card_generator'],
                403,
            );
        }

        $filters = $request->validate([
            // Absent means every card issued for this event. An explicit list is
            // what a "kirim ulang ke orang ini" button sends.
            'card_ids' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('id_card.max_recipients')],
            'card_ids.*' => ['uuid'],
        ]);

        $cards = $org->idCards()
            ->with('event:id,name,start_date')
            ->where('event_id', $model->id)
            ->when(
                $filters['card_ids'] ?? null,
                fn ($q, array $ids) => $q->whereIn('id', $ids),
            )
            ->get();

        if ($cards->isEmpty()) {
            return ApiResponse::error('Belum ada kartu terbit untuk event ini.', null, 422);
        }

        ['groups' => $groups, 'unsendable' => $unsendable] = $this->cards->groupForSending($cards);

        if ($groups === []) {
            return ApiResponse::error(
                'Tidak ada kartu yang punya alamat email tujuan. Tim tanpa akun manajer dan petugas tanpa email harus dicetak.',
                null,
                422,
            );
        }

        foreach ($groups as $cardIds) {
            SendIdCardsJob::dispatch($cardIds);
        }

        $sent = array_sum(array_map('count', $groups));

        return ApiResponse::success(
            ['emails' => count($groups), 'cards' => $sent, 'skipped' => $unsendable],
            // The skipped count is stated, never swallowed: a team with no
            // manager account is ordinary, and "40 kartu dikirim" printed over
            // 55 people reads as a bug rather than a fact.
            $sent.' kartu dikirim ke '.count($groups).' alamat email.'
                .($unsendable > 0 ? " {$unsendable} dilewati (tanpa alamat email)." : ''),
        );
    }

    /**
     * Stream one card's PNG. The bucket key never leaves the server.
     *
     * Deliberately ungated on the plan, exactly like
     * CertificateController::download(): a card rendered under an entitlement
     * that was paid for must stay reachable, and refusing to hand over a file
     * that already exists helps nobody.
     */
    public function downloadCard(Request $request, string $organization, string $card): StreamedResponse|JsonResponse
    {
        $model = $this->org($request)->idCards()->where('id', $card)->firstOrFail();

        if (! $model->png_key) {
            return ApiResponse::error('Berkas kartu belum tersedia.', null, 404);
        }

        return $this->cards->storage()->download($model->png_key, $model->filename(), [
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
