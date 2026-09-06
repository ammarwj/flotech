<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\DomainService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The routing table the Next middleware reads: which hostname belongs to which
 * event.
 *
 * Public and unauthenticated on purpose — the middleware runs before any user
 * exists, and every domain in here is already public knowledge (it is served to
 * anyone who types it). It leaks nothing an event page does not.
 */
class DomainController extends Controller
{
    public function index(): JsonResponse
    {
        // Only certified domains (see DomainService::active). A domain that is
        // merely assigned has no certificate yet, so routing traffic to it would
        // hand visitors a TLS error instead of a page.
        return ApiResponse::success(['domains' => DomainService::active()]);
    }
}
