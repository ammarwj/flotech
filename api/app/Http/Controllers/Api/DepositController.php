<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\DepositService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Security deposit monitoring, read-only.
 *
 * Behind `tenant` only, not `org.admin` — same call as the discipline read:
 * this is derived data with no money moved through the platform, and the
 * operator running the table has as much reason to see it as the organizer.
 */
class DepositController extends Controller
{
    public function __construct(protected DepositService $deposits) {}

    public function index(Organization $organization, Event $event): JsonResponse
    {
        abort_unless($event->organization_id === $organization->id, 404);

        return ApiResponse::success($this->deposits->forEvent($event));
    }
}
