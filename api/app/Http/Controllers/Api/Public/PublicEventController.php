<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\RegisterTeamRequest;
use App\Http\Resources\MatchResource;
use App\Http\Resources\PublicBankAccountResource;
use App\Http\Resources\PublicEventListResource;
use App\Http\Resources\PublicEventResource;
use App\Http\Resources\TeamResource;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Team;
use App\Notifications\NewTeamRegistered;
use App\Notifications\TeamRegistrationSubmitted;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Services\PlayerStatService;
use App\Services\RegistrationService;
use App\Services\StandingService;
use App\Services\TeamRosterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicEventController extends Controller
{
    use Concerns\ResolvesPublicEvent;

    public function __construct(
        protected PlanGate $gate,
        protected RegistrationService $registration,
        protected TeamRosterService $roster,
    ) {}

    /**
     * Public event catalog: every published event, newest live ones first.
     */
    public function index(Request $request): JsonResponse
    {
        $page = Event::query()
            ->where('status', '!=', 'draft')
            ->with(['organization', 'categories'])
            ->withCount(['teams as approved_teams_count' => fn ($q) => $q->where('status', 'approved')])
            ->withExists(['ticketCategories as tickets_on_sale' => fn ($q) => $q->where('is_active', true)])
            ->when($request->query('org'), fn ($q, $slug) => $q->whereHas(
                'organization',
                fn ($o) => $o->where('slug', $slug)
            ))
            ->when($request->query('sport'), fn ($q, $sport) => $q->where('sport_type', $sport))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($w) => $w
                    ->where('name', 'ilike', $like)
                    ->orWhere('location_name', 'ilike', $like)
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', $like)));
            })
            // Events people can still act on float to the top; finished and
            // cancelled ones sink but stay browsable as an archive.
            ->orderByRaw("case when status in ('open', 'registration_closed', 'ongoing') then 0 else 1 end")
            ->orderBy('start_date', 'desc')
            ->paginate(min((int) $request->query('per_page', 12), 50));

        return ApiResponse::success([
            'items' => PublicEventListResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Public landing data for an event addressed by org + event slug.
     */
    public function show(string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $event->load([
            'organization',
            'categories',
            'teams' => fn ($q) => $q->where('status', 'approved')->orderBy('name'),
            // jersey_number is free text, so sort numerically when it looks like a
            // number and push the rest (blank / "GK-2") to the bottom.
            'teams.players' => fn ($q) => $q
                ->orderByRaw("(jersey_number ~ '^[0-9]+$') desc")
                ->orderByRaw("case when jersey_number ~ '^[0-9]+$' then jersey_number::int end asc")
                ->orderBy('full_name'),
            'sponsors',
            'photos',
        ]);

        return ApiResponse::success(new PublicEventResource($event));
    }

    /**
     * Public schedule (fixtures) for one competition category.
     */
    public function matches(string $orgSlug, string $eventSlug, string $categorySlug): JsonResponse
    {
        $category = $this->category($this->resolve($orgSlug, $eventSlug), $categorySlug);

        $matches = $category->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->orderByRaw("coalesce(stage, '') asc")
            ->orderBy('round')
            ->orderBy('order')
            ->get();

        return ApiResponse::success(MatchResource::collection($matches));
    }

    /**
     * Public league standings for one competition category.
     */
    public function standings(StandingService $standings, string $orgSlug, string $eventSlug, string $categorySlug): JsonResponse
    {
        $category = $this->category($this->resolve($orgSlug, $eventSlug), $categorySlug);

        return ApiResponse::success($standings->compute($category));
    }

    /**
     * Player stats recorded in one match, for the public match detail. Unlike
     * the organizer's editor payload this carries no rosters — only the players
     * who actually registered something, since nothing here is editable.
     */
    public function matchStats(string $orgSlug, string $eventSlug, string $matchId): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $match = GameMatch::with(['homeTeam', 'awayTeam', 'stats.player'])->findOrFail($matchId);

        // The slug pair is the whole of the authorization here, so the match has
        // to belong to that event — otherwise any match id is readable through
        // any published event's URL.
        abort_if($match->event_id !== $event->id, 404);

        return ApiResponse::success([
            'columns' => Catalog::statColumns($event->sport_type),
            'home_team' => $this->matchStatLine($match, $match->homeTeam, $event->sport_type),
            'away_team' => $this->matchStatLine($match, $match->awayTeam, $event->sport_type),
        ]);
    }

    /**
     * One side of a match detail: the players with something to their name.
     *
     * @return array<string, mixed>|null
     */
    protected function matchStatLine(GameMatch $match, ?Team $team, ?string $sport): ?array
    {
        if (! $team) {
            return null;
        }

        // Scorers first — that is what a match detail is read for. Ties fall
        // back to name so the order is stable between requests.
        $goalKey = collect(Catalog::statColumns($sport))->firstWhere('role', 'goal')['key'] ?? null;

        $players = $match->stats
            ->where('team_id', $team->id)
            ->filter(fn ($s) => $s->player !== null && $s->value > 0)
            ->groupBy('player_id')
            ->map(fn ($rows) => [
                'id' => $rows->first()->player->id,
                'full_name' => $rows->first()->player->full_name,
                'jersey_number' => $rows->first()->player->jersey_number,
                'stats' => $rows->mapWithKeys(fn ($s) => [$s->stat_key => $s->value]),
            ])
            ->sortBy([
                fn ($a, $b) => ($b['stats'][$goalKey] ?? 0) <=> ($a['stats'][$goalKey] ?? 0),
                fn ($a, $b) => strcmp($a['full_name'], $b['full_name']),
            ])
            ->values();

        return [
            'id' => $team->id,
            'name' => $team->name,
            'players' => $players,
        ];
    }

    /**
     * Public player leaderboard for one competition category.
     */
    public function leaderboard(PlayerStatService $stats, string $orgSlug, string $eventSlug, string $categorySlug): JsonResponse
    {
        $category = $this->category($this->resolve($orgSlug, $eventSlug), $categorySlug);

        return ApiResponse::success($stats->leaderboard($category));
    }

    /**
     * Register a team for the event (open to the public; links to the
     * authenticated user as manager when a token is provided).
     */
    public function register(RegisterTeamRequest $request, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        if (! $event->isRegistrationOpen()) {
            return ApiResponse::error('Pendaftaran untuk event ini sedang ditutup.', null, 422);
        }

        $org = $event->organization;
        $data = $request->validated();

        $category = $event->categories()->find($data['category_id']);
        if (! $category) {
            return ApiResponse::error('Kategori tidak ditemukan.', ['category_id' => ['Kategori tidak valid.']], 422);
        }
        $category->setRelation('event', $event);

        // Each category runs its own team cap; the plan limit is event-wide.
        $categoryTeams = $category->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
        if ($category->max_teams !== null && $categoryTeams >= $category->max_teams) {
            return ApiResponse::error('Kuota tim untuk kategori ini sudah penuh.', null, 422);
        }

        $eventTeams = $event->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
        if (! $this->gate->withinLimit($org, 'max_teams_per_event', $eventTeams)) {
            return ApiResponse::error('Kuota tim paket penyelenggara sudah tercapai.', null, 422);
        }

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'logo_url' => $data['logo_url'] ?? null,
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'status' => 'pending',
            'registered_at' => Carbon::now(),
            'manager_user_id' => auth('api')->user()?->id,
        ]);
        // startPayment charges this category's fee — attach it so it doesn't re-query.
        $team->setRelation('category', $category);

        // Through the roster service, not straight into the tables: it is what
        // validates a player's position against the sport's master list, and a
        // public form is exactly where an unknown one would arrive.
        $this->roster->syncPlayers($team, $data['players'] ?? []);
        $this->roster->syncDocuments($team, $data['documents'] ?? []);

        // Charge the registration fee when the event has one; free events are
        // settled immediately inside startPayment().
        $payment = $this->registration->startPayment($team, $org);

        $this->announce($team, $org);

        $message = $team->fresh()->payment_status === 'paid'
            ? 'Pendaftaran tim berhasil dikirim. Menunggu persetujuan penyelenggara.'
            : 'Pendaftaran dibuat. Selesaikan pembayaran biaya pendaftaran untuk mengirim ke penyelenggara.';

        return ApiResponse::success([
            'team' => new TeamResource($team->fresh()->load(['players', 'documents', 'event', 'category'])),
            'snap_token' => $payment['snap_token'],
            'redirect_url' => $payment['redirect_url'],
            'mock' => $payment['mock'],
            'payment_method' => $payment['payment_method'],
            'bank_account' => $payment['bank_account']
                ? new PublicBankAccountResource($payment['bank_account'])
                : null,
        ], $message, 201);
    }

    /**
     * Tell both sides a registration landed: the manager that it arrived, the
     * organizer that someone is waiting on their verdict.
     *
     * A team registered by a guest has no manager account and gets no mail — the
     * teams table holds a phone number, not an email address. Swallows its own
     * errors: the registration is already saved, and a queue hiccup must not turn
     * a successful signup into a 500.
     */
    protected function announce(Team $team, Organization $org): void
    {
        try {
            $team->manager?->notify(new TeamRegistrationSubmitted($team));
            $org->owner?->notify(new NewTeamRegistered($team));
        } catch (Throwable $e) {
            Log::error('Gagal mengirim notifikasi pendaftaran tim', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a competition category by slug within an event (404 otherwise).
     * The parent event is attached so the category's sport/date accessors don't
     * re-query.
     */
    protected function category(Event $event, string $categorySlug): EventCategory
    {
        $category = $event->categories()->where('slug', $categorySlug)->firstOrFail();
        $category->setRelation('event', $event);

        return $category;
    }
}
