<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\RegisterTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Notifications\TeamStatusChanged;
use App\Services\PlanGate;
use App\Services\TeamRosterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class RegistrationController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected TeamRosterService $roster,
    ) {}

    /**
     * List all team registrations for an event (organizer view).
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $teams = $this->event($request, $event)
            ->teams()
            ->with(['players', 'documents', 'category'])
            ->latest('registered_at')
            ->get();

        return ApiResponse::success(TeamResource::collection($teams));
    }

    /**
     * Enter a team by hand (offline registration).
     *
     * Not every tournament is filled through this app: teams still sign up over
     * WhatsApp, on paper, or by paying the organizer directly. Such a team is
     * approved on arrival — the organizer entering it *is* the verification —
     * and its fee is settled outside the platform.
     *
     * That last part is why nothing is credited to the wallet: the money never
     * passed through us, so inventing a ledger entry for it would make the
     * organizer's balance claim funds the platform is not holding.
     */
    public function store(RegisterTeamRequest $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $data = $request->validated();
        $category = $eventModel->categories()->findOrFail($data['category_id']);

        // The same two ceilings the public form respects — an offline entry may
        // not be a way around the category's quota or the plan's team limit.
        $categoryTeams = $category->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
        if ($category->max_teams !== null && $categoryTeams >= $category->max_teams) {
            return ApiResponse::error('Kuota tim untuk kategori ini sudah penuh.', null, 422);
        }

        $eventTeams = $eventModel->teams()->whereNotIn('status', ['rejected', 'withdrawn'])->count();
        if (! $this->gate->withinLimit($org, 'max_teams_per_event', $eventTeams)) {
            return ApiResponse::error(
                'Batas jumlah tim per event untuk paketmu sudah tercapai.',
                ['feature' => 'max_teams_per_event'],
                403,
            );
        }

        $team = DB::transaction(function () use ($eventModel, $category, $data) {
            $team = $eventModel->teams()->create([
                'category_id' => $category->id,
                'name' => $data['name'],
                'logo_url' => $data['logo_url'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'status' => 'approved',
                'registered_at' => Carbon::now(),
                'approved_at' => Carbon::now(),
                // Settled offline: paid as far as the tournament is concerned,
                // but zero rupiah of it belongs to the platform.
                'payment_status' => 'paid',
                'payment_amount' => 0,
                'platform_fee' => 0,
            ]);

            $this->roster->syncPlayers($team, $data['players'] ?? []);
            $this->roster->syncDocuments($team, $data['documents'] ?? []);

            return $team;
        });

        return ApiResponse::success(
            new TeamResource($team->fresh()->load(['players', 'documents', 'category'])),
            'Tim berhasil ditambahkan.',
            201,
        );
    }

    /**
     * Edit a team's details and roster from the organizer side.
     *
     * Manually entered teams have no participant account behind them, so without
     * this there would be no way to fix a typo or add a player who turned up
     * late — the participant dashboard is not reachable for them.
     */
    public function update(RegisterTeamRequest $request, string $organization, string $event, string $team): JsonResponse
    {
        $eventModel = $this->event($request, $event);
        $teamModel = $eventModel->teams()->findOrFail($team);

        $data = $request->validated();
        $category = $eventModel->categories()->findOrFail($data['category_id']);

        DB::transaction(function () use ($teamModel, $category, $data) {
            $teamModel->update([
                'category_id' => $category->id,
                'name' => $data['name'],
                'logo_url' => $data['logo_url'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);

            $this->roster->syncPlayers($teamModel, $data['players'] ?? []);

            if (array_key_exists('documents', $data)) {
                $this->roster->syncDocuments($teamModel, $data['documents']);
            }
        });

        return ApiResponse::success(
            new TeamResource($teamModel->fresh()->load(['players', 'documents', 'category'])),
            'Data tim diperbarui',
        );
    }

    /**
     * Approve / reject / change a registration's status.
     */
    public function updateStatus(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $eventModel = $this->event($request, $event);
        $teamModel = $eventModel->teams()->findOrFail($team);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'disqualified', 'withdrawn'])],
            'group_name' => ['nullable', 'string', 'max:10'],
        ]);

        $previous = $teamModel->status;

        $teamModel->update([
            'status' => $validated['status'],
            'group_name' => $validated['group_name'] ?? $teamModel->group_name,
            'approved_at' => $validated['status'] === 'approved' ? Carbon::now() : null,
        ]);

        // Only on a real transition: re-saving "approved" (to set a group, say)
        // must not mail the manager the same verdict twice.
        if ($previous !== $validated['status']) {
            $this->announceStatus($teamModel, $validated['status']);
        }

        return ApiResponse::success(new TeamResource($teamModel->load(['players', 'documents'])), 'Status pendaftaran diperbarui');
    }

    /**
     * Mail the manager the organizer's verdict.
     *
     * A team the organizer typed in themselves (offline entry) has no manager
     * account and gets nothing — teams carry a phone number, not an email. The
     * verdict is already saved, so a queue hiccup must not turn it into a 500.
     */
    protected function announceStatus(Team $team, string $status): void
    {
        if (! in_array($status, TeamStatusChanged::NOTIFIABLE, true)) {
            return;
        }

        try {
            $team->manager?->notify(new TeamStatusChanged($team->load('event'), $status));
        } catch (Throwable $e) {
            Log::error('Gagal mengirim notifikasi status tim', [
                'team_id' => $team->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    protected function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
