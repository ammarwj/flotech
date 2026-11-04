<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RaceEntryResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Fun run entries filed by the signed-in participant.
 *
 * Sibling of MyTeamController, and deliberately a sibling rather than more
 * methods on it: the two share no row, no lifecycle and no ownership column
 * (`teams.manager_user_id` against `race_entries.user_id`). What they do share
 * is the posture — ownership comes from the relation, so an entry belonging to
 * somebody else is simply not in the query.
 *
 * `index()` and nothing else, and the gap is deliberate. Everything a runner
 * can *do* to an entry already lives behind the unguessable id
 * (`/race-entries/{id}`: the proof upload, the invoice, the receipt, the QR),
 * because a ticket buyer or runner may never have signed up at all. Copying
 * those four doors here would give every one of them a second enforcement
 * point to keep in step, for a page that only has to say "here is what you
 * entered, open it".
 */
class MyRaceEntryController extends Controller
{
    /**
     * Entries this account filed, newest first.
     *
     * `event.organization` is loaded for the event name the card prints, and
     * `bankAccounts` deliberately is NOT: `RaceEntryResource::payTo()` only
     * answers when the whole chain is eager-loaded, and the transfer details
     * belong to the one page that collects the money — a list of past
     * registrations has no reason to carry an organizer's account number.
     */
    public function index(): JsonResponse
    {
        $entries = auth('api')->user()
            ->raceEntries()
            ->with(['category', 'event.organization', 'documents'])
            ->latest('created_at')
            ->get();

        return ApiResponse::success(RaceEntryResource::collection($entries));
    }
}
