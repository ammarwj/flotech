<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Race\RegisterRaceEntryRequest;
use App\Http\Resources\PublicBankAccountResource;
use App\Http\Resources\RaceCategoryResource;
use App\Http\Resources\RaceEntryResource;
use App\Http\Resources\RacePeriodResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Services\Catalog;
use App\Services\MidtransService;
use App\Services\ParticipantDocumentService;
use App\Services\PaymentFeeCalculator;
use App\Services\PaymentRails;
use App\Services\PlanGate;
use App\Services\RaceEntryService;
use App\Support\ApiResponse;
use App\Support\RacePricing;
use App\Support\RegistrationForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The runner's side of a fun run: what is on sale, entering, and the receipt.
 *
 * Shaped on PublicTicketController, including the three things that make a
 * public money endpoint safe: the plan gate refuses with 422 rather than 403,
 * the manual rail never reaches the settlement shortcut, and the documents are
 * reachable with nothing but the entry's unguessable id.
 */
class PublicRaceController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected RaceEntryService $entries,
        protected RacePricing $pricing,
        protected MidtransService $midtrans,
        protected PaymentRails $rails,
        protected PaymentFeeCalculator $fees,
        protected ParticipantDocumentService $documents,
    ) {}

    /**
     * What a fun run is selling: its categories with their current price, and
     * the windows those prices belong to.
     *
     * Both in one payload rather than two endpoints: the page shows a price
     * and the date it changes in the same sentence, and two requests would let
     * them disagree across a period boundary.
     */
    public function categories(string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $categories = $event->raceCategories()
            ->with(['prices', 'event.racePeriods'])
            ->where('is_active', true)
            ->get();

        $current = $this->pricing->periodAt($event);

        return ApiResponse::success([
            'categories' => RaceCategoryResource::collection($categories),
            'periods' => RacePeriodResource::listOf($event->racePeriods, $current?->id),
            // Published rather than left to the client to infer from a null
            // `current_price` on every category: "no window is open" and "this
            // category has no price in the open window" are different sentences
            // on the page, and only the server can tell them apart.
            'current_period' => $current
                ? new RacePeriodResource($current, $current->id)
                : null,
        ]);
    }

    /**
     * Enter the race: hold a place, snapshot the price, start payment.
     *
     * The order of the guards is deliberate and mirrors
     * PublicEventController::register(): the plan gate first (a plan without
     * online registration never opens at all, so "closed" would be a
     * misleading answer), then the window, then the category.
     */
    public function register(RegisterRaceEntryRequest $request, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        // Not a fun run: 404 rather than 422. The route does not exist for a
        // football event, and saying "wrong sport" would describe a door this
        // caller cannot see anyway.
        abort_if(Catalog::scoringOf($event->sport_type) !== 'none', 404);

        // 422 rather than 403, matching every other public refusal: to a runner
        // this is "not open", not "you are forbidden", and a 403 on a public
        // form reads as a permission problem they cannot act on. The `feature`
        // key is carried so both enforcement points stay greppable — this is
        // the same deliberate exception to isPlanLimitError() the team
        // registration form makes.
        if (! $this->gate->allows($event, 'online_registration')) {
            return ApiResponse::error(
                'Pendaftaran online tidak tersedia untuk event ini.',
                ['feature' => 'online_registration'],
                422,
            );
        }

        if (! $event->isRegistrationOpen()) {
            return ApiResponse::error('Pendaftaran untuk event ini sedang ditutup.', null, 422);
        }

        $data = $request->validated();

        $category = $event->raceCategories()
            ->with(['prices', 'event.racePeriods'])
            ->find($data['race_category_id']);

        if (! $category) {
            return ApiResponse::error(
                'Kategori tidak ditemukan.',
                ['race_category_id' => ['Kategori tidak valid.']],
                422,
            );
        }

        if (! $category->is_active) {
            return ApiResponse::error(
                'Kategori ini sedang tidak dibuka.',
                ['race_category_id' => ['Kategori ini sedang tidak dibuka.']],
                422,
            );
        }

        // Read before anything is written, and re-read inside the transaction
        // below is deliberately NOT done: the quota here is advisory in the
        // same way `ticket_categories.sold` is, and two runners racing for the
        // last place is a far smaller problem than a race that cannot sell its
        // last place at all. What must not race is the price, and that is
        // resolved once inside hold().
        if ($category->remaining() !== null && $category->remaining() <= 0) {
            return ApiResponse::error(
                'Kuota untuk kategori ini sudah penuh.',
                ['race_category_id' => ['Kuota kategori ini sudah penuh.']],
                422,
            );
        }

        // Throws PaymentException (422) when no window is open or this category
        // has no price in it. Asked here, before any row is written, so a
        // refused entry burns no invoice number — the same reason
        // platformDestination() is planless.
        $quote = $this->pricing->quote($category);
        $price = $quote['price'];

        if ($denied = $this->assertFormComplete($event, $data)) {
            return $denied;
        }

        // Throws when the organizer cannot collect at all. A bank account back
        // means the gateway is off and the runner transfers directly; null
        // means Midtrans, or a free entry.
        $bank = $this->rails->destinationFor($event, $price);
        $manual = $bank !== null;

        $channel = null;
        $gatewayFee = 0.0;
        $gatewayTax = 0.0;
        $serviceFee = 0.0;
        $enabledPayments = [];

        if (! $manual && $price > 0) {
            if (empty($data['payment_channel'])) {
                return ApiResponse::error(
                    'Pilih metode pembayaran.',
                    ['payment_channel' => ['Metode pembayaran wajib dipilih.']],
                    422,
                );
            }

            // No unit multiplier: one runner, one place. AUDIENCE_RACE_ENTRY
            // rather than one of the other three — see its docblock.
            $breakdown = $this->fees->forChannel(
                $data['payment_channel'],
                $price,
                PaymentFeeCalculator::AUDIENCE_RACE_ENTRY,
            );
            $channel = $breakdown['channel'];
            $gatewayFee = $breakdown['gateway_fee'];
            $gatewayTax = $breakdown['gateway_tax'];
            $serviceFee = $breakdown['service_fee'];
            $enabledPayments = $breakdown['midtrans_payments'];
        }

        $orderId = $this->entries->newOrderId();

        // One transaction so a half-made entry leaves nothing behind: either
        // the runner's documents are all stored or the place they were holding
        // is never taken. Same reasoning as the team registration form.
        $entry = DB::transaction(function () use ($category, $data, $orderId, $manual, $channel, $gatewayFee, $serviceFee, $gatewayTax) {
            $entry = $this->entries->hold(
                $category,
                [
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                    'custom_fields' => $this->cleanAnswers($category->event, $data['custom_fields'] ?? []),
                ],
                $orderId,
                auth('api')->id(),
                $manual ? 'manual' : 'gateway',
                $manual ? $this->rails->deadline() : null,
                $channel,
                $gatewayFee,
                $serviceFee,
                $gatewayTax,
            );

            foreach ($data['documents'] ?? [] as $doc) {
                $entry->documents()->create([
                    'document_type' => $doc['document_type'],
                    'file_url' => $doc['file_url'],
                    'file_name' => $doc['file_name'] ?? null,
                    'file_size_bytes' => $doc['file_size_bytes'] ?? null,
                    'uploaded_at' => now(),
                ]);
            }

            return $entry;
        });

        $snap = ['token' => null, 'redirect_url' => null, 'mock' => false];

        if (! $manual && $price > 0) {
            $snap = $this->midtrans->createSnapTransaction(
                ['order_id' => $orderId, 'gross_amount' => (int) round($entry->gross_amount)],
                ['first_name' => $data['full_name'], 'email' => $data['email']],
                rtrim((string) config('app.frontend_url'), '/').'/race-entries/'.$entry->id,
                $enabledPayments,
            );

            if ($snap['token']) {
                $entry->update(['midtrans_token' => $snap['token']]);
            }
        }

        // A free entry, or dev with no Midtrans credentials, settles on the
        // spot. A manual entry must never reach this: `mock` means "no server
        // key", not "paid", and settling one would issue a receipt for money
        // nobody sent.
        $settled = ! $manual && ($snap['mock'] || $price <= 0);
        if ($settled) {
            $this->entries->markPaid($entry);
        }

        return ApiResponse::success([
            'entry' => new RaceEntryResource(
                $entry->fresh()->load(['category', 'event.organization.bankAccounts', 'documents']),
            ),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $settled,
            'payment_method' => $manual ? 'manual' : 'gateway',
            'bank_account' => $bank ? new PublicBankAccountResource($bank) : null,
        ], $settled
            ? 'Pendaftaran berhasil. Kwitansi sudah bisa diunduh.'
            : 'Pendaftaran dibuat. Selesaikan pembayaran untuk mengaktifkannya.', 201);
    }

    /**
     * One entry, for the public proof page.
     *
     * The organization chain is what lets an unpaid manual entry tell the
     * runner where to transfer (see RaceEntryResource::payTo()).
     */
    public function entry(string $raceEntry): JsonResponse
    {
        $entry = RaceEntry::with([
            'category',
            'event.organization.bankAccounts',
            'documents',
        ])->findOrFail($raceEntry);

        // The one read in this app that writes, and it is confined to here on
        // purpose: an entry paid before check-in shipped has no QR, and this
        // page is the only place the code is ever needed. See
        // RaceEntryService::ensureQrCode() for why the write is a conditional
        // UPDATE and not a plain save — this endpoint polls every four seconds
        // and a runner may have it open twice.
        $entry = $this->entries->ensureQrCode($entry);

        return ApiResponse::success(new RaceEntryResource($entry->load([
            'category',
            'event.organization.bankAccounts',
            'documents',
        ])));
    }

    /**
     * The runner's own invoice and receipt.
     *
     * Public for the same reason `entry()` is: a runner may enter without ever
     * signing up, so the unguessable id is the credential — and in this phase
     * the receipt IS the thing shown at the start line, so locking it behind a
     * login would lock people out of the only proof they have.
     *
     * A free entry has no numbers and therefore no documents: 404 rather than a
     * Rp 0 document claiming a transaction that never happened.
     */
    public function invoice(string $raceEntry): Response
    {
        $entry = RaceEntry::findOrFail($raceEntry);

        abort_if($entry->invoice_number === null, 404);

        return $this->documents->invoice($entry);
    }

    public function receipt(string $raceEntry): Response|JsonResponse
    {
        $entry = RaceEntry::findOrFail($raceEntry);

        abort_if($entry->receipt_number === null, 404);

        if (! $entry->paid_at) {
            return ApiResponse::error('Kwitansi baru tersedia setelah pembayaran lunas.', null, 403);
        }

        return $this->documents->receipt($entry);
    }

    /**
     * Upload the transfer receipt for a manual entry.
     *
     * Public, like the proof page it lives on — the entry's unguessable id is
     * the only credential there is, exactly what `entry()` already relies on.
     */
    public function proof(Request $request, string $raceEntry): JsonResponse
    {
        $data = $request->validate([
            'payment_proof_url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $entry = RaceEntry::findOrFail($raceEntry);

        $this->entries->submitProof($entry, $data['payment_proof_url']);

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load([
                'category',
                'event.organization.bankAccounts',
                'documents',
            ])),
            'Bukti pembayaran terkirim. Menunggu verifikasi penyelenggara.',
        );
    }

    /**
     * Refuse an entry that leaves a required field or document empty.
     *
     * The event's own registration form, reused rather than reinvented: the
     * same schema the team form renders is what a race asks with. Only the team
     * sections apply — a runner has no roster and no bench, so `player_fields`
     * and `team_official_fields` are not read here and the organizer's builder
     * is what decides to leave them empty.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertFormComplete(Event $event, array $data): ?JsonResponse
    {
        $form = RegistrationForm::forEvent($event);
        $answers = $data['custom_fields'] ?? [];
        $uploaded = array_column($data['documents'] ?? [], 'document_type');
        $errors = [];

        foreach ($form->teamFields as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            $value = $answers[$field['key']] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                $errors["custom_fields.{$field['key']}"] = "{$field['label']} wajib diisi.";
            }
        }

        foreach ($form->teamDocuments as $doc) {
            if (($doc['required'] ?? false) && ! in_array($doc['key'], $uploaded, true)) {
                $errors['documents'] = "Dokumen \"{$doc['label']}\" wajib diunggah.";
            }
        }

        if ($errors === []) {
            return null;
        }

        return ApiResponse::error('Ada data yang belum lengkap.', $errors, 422);
    }

    /**
     * Keep only the answers this event actually asks for.
     *
     * Mirrors TeamRosterService::cleanAnswers(): a public form is exactly where
     * a key nobody defined would arrive, and storing it would leave a column
     * nothing ever renders.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>|null
     */
    protected function cleanAnswers(Event $event, array $answers): ?array
    {
        $allowed = array_column(RegistrationForm::forEvent($event)->teamFields, 'key');
        $clean = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $answers) && is_scalar($answers[$key])) {
                $clean[$key] = trim((string) $answers[$key]);
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Resolve a published event by org + event slug (404 for drafts).
     */
    protected function resolve(string $orgSlug, string $eventSlug): Event
    {
        $org = Organization::where('slug', $orgSlug)->firstOrFail();

        return $org->events()
            ->where('slug', $eventSlug)
            ->where('status', '!=', 'draft')
            ->with('racePeriods')
            ->firstOrFail();
    }
}
