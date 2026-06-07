<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\RegisterTeamRequest;
use App\Http\Resources\MatchResource;
use App\Http\Resources\PublicEventResource;
use App\Http\Resources\TeamResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Services\PlayerStatService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PublicEventController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    /**
     * Public landing data for an event addressed by org + event slug.
     */
    public function show(string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $event->load([
            'organization',
            'teams' => fn ($q) => $q->where('status', 'approved')->orderBy('name'),
        ]);

        return ApiResponse::success(new PublicEventResource($event));
    }

    /**
     * Public schedule (fixtures) for an event.
     */
    public function matches(string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $matches = $event->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('round')
            ->orderBy('order')
            ->get();

        return ApiResponse::success(MatchResource::collection($matches));
    }

    /**
     * Public league standings for an event.
     */
    public function standings(StandingService $standings, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        return ApiResponse::success($standings->compute($event));
    }

    /**
     * Public player leaderboard for an event.
     */
    public function leaderboard(PlayerStatService $stats, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        return ApiResponse::success($stats->leaderboard($event));
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
        $activeTeams = $event->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();

        if ($event->max_teams !== null && $activeTeams >= $event->max_teams) {
            return ApiResponse::error('Kuota tim untuk event ini sudah penuh.', null, 422);
        }

        if (! $this->gate->withinLimit($org, 'max_teams_per_event', $activeTeams)) {
            return ApiResponse::error('Kuota tim paket penyelenggara sudah tercapai.', null, 422);
        }

        $data = $request->validated();

        $team = $event->teams()->create([
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'jersey_color' => $data['jersey_color'] ?? null,
            'logo_url' => $data['logo_url'] ?? null,
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'status' => 'pending',
            'registered_at' => Carbon::now(),
            'manager_user_id' => auth('api')->user()?->id,
        ]);

        foreach ($data['players'] as $player) {
            $team->players()->create($player);
        }

        foreach ($data['documents'] ?? [] as $doc) {
            $team->documents()->create($doc + ['uploaded_at' => Carbon::now()]);
        }

        return ApiResponse::success(
            new TeamResource($team->load(['players', 'documents'])),
            'Pendaftaran tim berhasil dikirim. Menunggu persetujuan penyelenggara.',
            201,
        );
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
            ->firstOrFail();
    }
}
