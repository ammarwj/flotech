<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanFeatureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Jobs\PurgeMediaJob;
use App\Models\Certificate;
use App\Jobs\ReleaseEventFundsJob;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Services\Catalog;
use App\Services\MediaCleanupService;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(protected PlanGate $gate, protected MediaCleanupService $media) {}

    public function index(Request $request): JsonResponse
    {
        $events = $this->org($request)->events()
            // Each category's own count, not just the event's: the event form
            // locks a category's participant_type once entrants exist.
            ->with(['plan.features', 'categories' => fn ($q) => $q->withCount('teams')])
            ->withCount('teams')
            ->latest()
            ->get();

        return ApiResponse::success(EventResource::collection($events));
    }

    /**
     * Create an event by spending a paid plan order.
     *
     * The whole thing runs in one transaction. syncCategories() already threw
     * ValidationException for participant_type, and today that leaves a created
     * event behind with no categories; the max_categories gate makes that
     * failure common rather than rare, and a rolled-back create is also what
     * stops a refused event from burning the organizer's credit.
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        $org = $this->org($request);

        $data = $request->validated();
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $event = DB::transaction(function () use ($org, $request, $data, $categories) {
            $order = $this->claimOrder($org, $request->input('plan_order_id'));

            $data['slug'] = $this->uniqueSlug($org, $data['slug'] ?? $data['name']);
            $data['status'] = 'draft';
            $data['plan_id'] = $order->plan_id;

            $event = $org->events()->create($data);
            // syncCategories gates on the plan; hand it over rather than let it
            // re-query a relation we already hold.
            $event->setRelation('plan', $order->plan);

            // The atomic claim. `whereNull('event_id')` inside the UPDATE is what
            // makes two concurrent creates unable to spend one credit —
            // lockForUpdate alone would let the second overwrite the first's
            // event_id. The unique index on event_id covers the other direction:
            // two orders claiming one event.
            $claimed = EventPlanOrder::whereKey($order->id)
                ->whereNull('event_id')
                ->update(['event_id' => $event->id, 'consumed_at' => now()]);

            if ($claimed === 0) {
                throw new PlanFeatureException(
                    'Paket itu baru saja dipakai untuk event lain. Muat ulang halaman ini.',
                    ['feature' => 'plan_order_required'],
                );
            }

            $this->syncCategories($event, $categories);

            return $event;
        });

        return ApiResponse::success(
            new EventResource($event->load(['plan.features', 'categories'])),
            'Event dibuat',
            201,
        );
    }

    /**
     * The paid, unspent plan this event will run on.
     *
     * `plan_order_id` is optional and exists because an organizer can hold
     * several credits at once: spending a Starter on a national championship
     * because it happened to be bought first is a mistake with no undo — the
     * plan is locked to the event from here on. Without one, oldest first: the
     * credit that has been idle longest.
     *
     * Ownership and state are checked here rather than in StoreEventRequest
     * because both need the organization, the same reason participant_type
     * validation lives in this controller.
     *
     * @throws PlanFeatureException
     */
    protected function claimOrder(Organization $org, ?string $orderId): EventPlanOrder
    {
        $order = $org->planOrders()->unconsumed()
            ->when($orderId, fn ($q) => $q->whereKey($orderId))
            ->with('plan.features')
            ->oldest('paid_at')
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new PlanFeatureException(
                'Kamu belum punya paket yang siap dipakai. Beli paket dulu untuk membuat event.',
                ['feature' => 'plan_order_required'],
            );
        }

        return $order;
    }

    public function show(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success(new EventResource(
            $this->find($request, $event)
                ->load(['plan.features', 'categories' => fn ($q) => $q->withCount('teams')])
                ->loadCount('teams'),
        ));
    }

    public function update(UpdateEventRequest $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);

        $data = $request->validated();
        $categories = $data['categories'] ?? null;
        unset($data['categories']);

        // rules_config is one column holding several independent rulebooks, so a
        // plain update() would let the form that knows about one of them delete
        // the rest. Merge per namespace instead — the same guard `officials`
        // needs on the registration form.
        if (array_key_exists('rules_config', $data)) {
            $data['rules_config'] = [
                ...($model->rules_config ?? []),
                ...($data['rules_config'] ?? []),
            ];
        }

        $model->update($data);

        if ($categories !== null) {
            $this->syncCategories($model, $categories);
        }

        return ApiResponse::success(new EventResource($model->load(['plan.features', 'categories'])), 'Event diperbarui');
    }

    /**
     * Move the event to its next status.
     *
     * The only door: `status` is deliberately absent from UpdateEventRequest,
     * so the transition table can't be walked around by saving the form. It
     * matters because reaching `finished` releases the ticket and registration
     * money the platform holds for this organizer — an irreversible payout, not
     * a field edit.
     */
    public function updateStatus(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Event::TRANSITIONS))],
        ]);

        if (! $model->canTransitionTo($validated['status'])) {
            return ApiResponse::error(
                $model->nextStatuses() === []
                    ? 'Event yang sudah selesai tidak bisa diubah lagi.'
                    : 'Status itu tidak bisa dicapai dari status event saat ini.',
                ['status' => ['Perpindahan status tidak diizinkan.']],
                422,
            );
        }

        return $this->transition($model, $validated['status']);
    }

    /**
     * Apply a status the caller has already established is allowed, and settle
     * whatever that status owes.
     */
    protected function transition(Event $model, string $status): JsonResponse
    {
        $restoring = $model->status === 'cancelled';

        $model->update([
            'status' => $status,
            // A snapshot, not something derived later: where the event stood is
            // the only thing that can bring it back, and by the time it is
            // wanted the status it would have been read from is gone. Cleared
            // on every other move so a second cancellation can never restore an
            // event to a status it left long ago.
            'status_before_cancel' => $status === 'cancelled' ? $model->status : null,
        ]);

        // Closing an event releases the ticket & registration money the
        // platform has been holding for this organizer.
        if ($status === 'finished') {
            ReleaseEventFundsJob::dispatch($model->id)->afterCommit();
        }

        return ApiResponse::success(
            new EventResource($model->load('categories')),
            // Restoring lands on an ordinary status, so the per-status message
            // would announce it as one: "Event dipublikasikan" for an event that
            // was only ever brought back from the dead.
            $restoring
                ? 'Event diaktifkan kembali'
                : (self::STATUS_MESSAGES[$status] ?? 'Status event diperbarui'),
        );
    }

    /** @var array<string, string> */
    protected const STATUS_MESSAGES = [
        'open' => 'Event dipublikasikan — pendaftaran tim dibuka',
        'registration_closed' => 'Pendaftaran ditutup',
        'ongoing' => 'Event ditandai sedang berlangsung',
        'finished' => 'Event diselesaikan — dana tertahan dicairkan',
        'cancelled' => 'Event dibatalkan',
    ];

    /**
     * Full-replace the event's categories from the submitted list. Rows carrying
     * an `id` are updated in place; new rows are created; categories no longer in
     * the list are removed (which cascades their teams and matches). A format
     * preset can seed a category's bracket_config ("Liga 2 Putaran" = league +
     * 2 legs) — what the organizer sent still wins.
     *
     * @param  array<int, array<string, mixed>>  $categories
     *
     * @throws ValidationException
     */
    protected function syncCategories(Event $event, array $categories): void
    {
        $modes = Catalog::participantModes($event->sport_type);
        $keep = [];

        // syncCategories is a full replace, so the submitted list *is* the
        // total — hence currentCount 0 and the whole count as `adding`.
        //
        // The check lives here rather than in EventCategoryRules because a
        // FormRequest cannot see the event's plan, and because both store() and
        // update() route through this method: duplicating it into two requests
        // is how `officials.*.id` validation ended up written twice.
        if (! $this->gate->withinLimit($event, 'max_categories', 0, count($categories))) {
            throw new PlanFeatureException(
                'Jumlah kategori melebihi batas paket event ini.',
                ['feature' => 'max_categories'],
            );
        }

        // The organizer's own per-category cap may not exceed the plan's.
        $planCap = $this->gate->limit($event, 'max_teams_per_category');

        foreach (array_values($categories) as $i => $cat) {
            // 422 with a field path rather than 403: this is a number typed into
            // a specific input, and the form binds field errors inline. Refused
            // rather than silently clamped — a clamp hides the cap from the
            // person choosing it.
            if ($planCap !== null && $planCap !== -1 && (int) ($cat['max_teams'] ?? 0) > $planCap) {
                throw ValidationException::withMessages([
                    "categories.{$i}.max_teams" => "Paket event ini membatasi {$planCap} entri per kategori.",
                ]);
            }

            $defaults = Catalog::formatDefaults($cat['tournament_format'] ?? null);
            $bracket = $cat['bracket_config'] ?? null;
            if ($defaults !== []) {
                $bracket = [...$defaults, ...($bracket ?? [])];
            }

            $existing = ! empty($cat['id']) ? $event->categories()->find($cat['id']) : null;
            $participantType = $this->participantTypeFor($cat, $existing, $modes, $i);

            $attributes = [
                'name' => $cat['name'],
                'slug' => $this->uniqueCategorySlug($event, $cat['slug'] ?? $cat['name'], $cat['id'] ?? null),
                'participant_type' => $participantType,
                'tournament_format' => $cat['tournament_format'],
                'bracket_config' => $bracket,
                // A template only means something for a squad tie on a sport that
                // also fields lone players. Storing one anywhere else would leave
                // usesRubbers() reading a config no code path can act on.
                'rubber_format' => $participantType === 'team' && in_array('single', $modes, true)
                    ? ($cat['rubber_format'] ?? null) ?: null
                    : null,
                'registration_fee' => $cat['registration_fee'] ?? 0,
                'max_teams' => $cat['max_teams'] ?? null,
                'sort_order' => $i,
            ];

            if ($existing) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $event->categories()->create($attributes)->id;
            }
        }

        $dropped = $event->categories()->whereNotIn('id', $keep)->pluck('id');

        if ($dropped->isNotEmpty()) {
            // Categories hold no files of their own; what leaks here is every
            // file of the teams they cascade away. Collected before the delete,
            // for the same reason as destroy().
            $urls = $this->media->teamUrls(
                $event->teams()->whereIn('category_id', $dropped)->pluck('id')->all()
            );

            $event->categories()->whereKey($dropped)->delete();

            PurgeMediaJob::dispatch($urls)->afterCommit();
        }
    }

    /**
     * The entrant shape this category is saved with.
     *
     * Two things can go wrong and both are validation errors, not silent
     * coercions. The sport may not field this shape at all (a football category
     * cannot be "ganda"); and the shape may no longer be free to change, because
     * teams have already registered against it — their rosters were built to it
     * (exactly one player, exactly two) and their names derived from it, so
     * flipping it retroactively invalidates entries that are already paid for.
     * Same snapshot reasoning as `payment_method` on an order.
     *
     * @param  array<string, mixed>  $cat
     * @param  array<int, string>  $modes
     *
     * @throws ValidationException
     */
    protected function participantTypeFor(array $cat, ?EventCategory $existing, array $modes, int $i): string
    {
        $type = $cat['participant_type'] ?? $existing?->participant_type ?? 'team';

        if (! in_array($type, $modes, true)) {
            throw ValidationException::withMessages([
                "categories.{$i}.participant_type" => 'Cabang olahraga ini tidak mendukung jenis peserta tersebut.',
            ]);
        }

        if ($existing && $type !== $existing->participant_type && $existing->teams()->exists()) {
            throw ValidationException::withMessages([
                "categories.{$i}.participant_type" => 'Jenis peserta tidak bisa diubah karena sudah ada peserta terdaftar.',
            ]);
        }

        return $type;
    }

    protected function uniqueCategorySlug(Event $event, string $source, ?string $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 1;

        while (
            $event->categories()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Deleting an event cascades all the way down — teams, rosters, matches,
     * orders, certificates. Their file keys only exist on those rows, so they
     * are read here, before the delete, and purged once the row is gone.
     *
     * Which is exactly why only an event that never began may be deleted. Two
     * separate harms sat behind the old unguarded delete:
     *
     *  - `event_plan_orders.event_id` is `nullOnDelete`, so removing the event
     *    handed the credit straight back and one payment bought unlimited
     *    events. `consumed_at` recorded that it had been spent, but nothing read
     *    it.
     *  - `certificates` and `ticket_orders` cascade. A certificate carries a
     *    public verification URL printed on the document itself, and
     *    CertificateController::download is deliberately left open so those keep
     *    resolving forever — a cascade quietly breaks every QR already handed
     *    out, along with the record of who paid for a ticket.
     *
     * A draft with nothing attached has neither problem, and refusing that would
     * punish a plain mis-click. Everything else is `cancelled`, not deleted:
     * status the event stops, history it keeps.
     */
    public function destroy(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);

        if ($blocker = $this->deletionBlocker($model)) {
            return ApiResponse::error(
                "Event ini tidak bisa dihapus karena {$blocker}. Batalkan saja lewat status Dibatalkan — datanya tetap utuh dan paketnya tidak hangus.",
                ['status' => [$blocker]],
                422,
            );
        }

        $urls = $this->media->eventUrls($model);

        // The credit only returns for an event that never began, so it is
        // released here rather than left to the foreign key. `consumed_at` is
        // the part `nullOnDelete` cannot reach, and scopeUnconsumed() reads it.
        EventPlanOrder::where('event_id', $model->id)
            ->update(['event_id' => null, 'consumed_at' => null]);

        $model->delete();

        PurgeMediaJob::dispatch($urls)->afterCommit();

        return ApiResponse::success(null, 'Event dihapus, paketnya kembali jadi kredit');
    }

    /**
     * Why this event may not be deleted, or null if it may.
     *
     * Phrased as the reason rather than a boolean because the message has to
     * name it: "tidak bisa dihapus" with no cause reads as a bug to the person
     * who just tried.
     */
    protected function deletionBlocker(Event $event): ?string
    {
        if ($event->status !== 'draft') {
            return 'sudah dipublikasikan';
        }

        // Belt and braces behind the status check: a draft should not be able to
        // hold any of these, but each one is something a person cannot get back.
        return match (true) {
            $event->teams()->exists() => 'sudah punya peserta terdaftar',
            $event->ticketOrders()->exists() => 'sudah punya pesanan tiket',
            Certificate::where('event_id', $event->id)->exists() => 'sudah menerbitkan sertifikat',
            default => null,
        };
    }

    /**
     * Publish a draft. Kept as its own verb because that is how the dashboard
     * words it, but it goes through the same transition table as everything
     * else rather than writing the status itself.
     */
    public function publish(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);

        if (! $model->canTransitionTo('open')) {
            return ApiResponse::error('Event ini sudah dipublikasikan.', ['status' => ['Sudah tidak berstatus draf.']], 422);
        }

        return $this->transition($model, 'open');
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    /**
     * Resolve an event scoped to the current organization (404 otherwise).
     */
    protected function find(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }

    protected function uniqueSlug(Organization $org, string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 1;

        while ($org->events()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
