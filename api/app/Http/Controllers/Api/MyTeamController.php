<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Services\RegistrationService;
use App\Services\TeamRosterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MyTeamController extends Controller
{
    public function __construct(
        protected RegistrationService $registration,
        protected TeamRosterService $roster,
    ) {}

    /**
     * Teams the authenticated participant manages (registered).
     */
    public function index(): JsonResponse
    {
        $teams = $this->scope()
            ->with(['event', 'players', 'documents'])
            ->latest('registered_at')
            ->get();

        return ApiResponse::success(TeamResource::collection($teams));
    }

    /**
     * A single managed team with its roster and documents.
     */
    public function show(string $team): JsonResponse
    {
        $model = $this->scope()->with(['event', 'players', 'documents'])->findOrFail($team);

        return ApiResponse::success(new TeamResource($model));
    }

    /**
     * Update team details and sync the player roster. Only allowed while the
     * registration is still editable (not rejected/disqualified/withdrawn).
     */
    public function update(Request $request, string $team): JsonResponse
    {
        $model = $this->scope()->with('players')->findOrFail($team);

        if (! $this->isEditable($model)) {
            return ApiResponse::error('Tim ini tidak dapat diubah lagi.', null, 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'required', 'string', 'max:20'],

            // Registration lets both of these be skipped, so this is where they
            // get completed. An empty array is a legitimate value (it clears the
            // list), which is why there is no `min:1`.
            'players' => ['sometimes', 'array'],
            'players.*.id' => ['nullable', 'string'],
            'players.*.full_name' => ['required', 'string', 'max:255'],
            'players.*.jersey_number' => ['nullable', 'string', 'max:5'],
            'players.*.position' => ['nullable', 'string', 'max:50'],

            'documents' => ['sometimes', 'array'],
            'documents.*.id' => ['nullable', 'string'],
            'documents.*.file_url' => ['required', 'string'],
            'documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.document_type' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($model, $data) {
            $model->update(array_intersect_key($data, array_flip([
                'name', 'logo_url', 'contact_name', 'contact_phone',
            ])));

            if (array_key_exists('players', $data)) {
                $this->roster->syncPlayers($model, $data['players']);
            }

            if (array_key_exists('documents', $data)) {
                $this->roster->syncDocuments($model, $data['documents']);
            }
        });

        return ApiResponse::success(
            new TeamResource($model->fresh()->load(['event', 'players', 'documents'])),
            'Data tim diperbarui',
        );
    }

    /**
     * Withdraw the team from the event.
     */
    public function withdraw(string $team): JsonResponse
    {
        $model = $this->scope()->findOrFail($team);

        if (in_array($model->status, ['rejected', 'disqualified', 'withdrawn'], true)) {
            return ApiResponse::error('Tim ini tidak dapat ditarik.', null, 422);
        }

        $model->update(['status' => 'withdrawn']);

        return ApiResponse::success(new TeamResource($model->fresh()), 'Tim ditarik dari turnamen');
    }

    /**
     * (Re)start the Midtrans payment for an unpaid registration fee.
     */
    public function pay(string $team): JsonResponse
    {
        $model = $this->scope()->with('event')->findOrFail($team);

        if ($model->payment_status === 'paid') {
            return ApiResponse::error('Pendaftaran ini sudah dibayar.', null, 422);
        }

        $payment = $this->registration->startPayment($model, $model->event->organization);

        return ApiResponse::success([
            'team' => new TeamResource($model->fresh()->load(['event', 'players', 'documents'])),
            'snap_token' => $payment['snap_token'],
            'redirect_url' => $payment['redirect_url'],
            'mock' => $payment['mock'],
        ], 'Pembayaran dimulai');
    }

    protected function isEditable(Team $team): bool
    {
        return ! in_array($team->status, ['rejected', 'disqualified', 'withdrawn'], true);
    }

    /**
     * Base query scoped to the authenticated participant's managed teams.
     */
    protected function scope()
    {
        return auth('api')->user()->managedTeams();
    }
}
