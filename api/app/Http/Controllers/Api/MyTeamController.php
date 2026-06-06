<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MyTeamController extends Controller
{
    /**
     * Teams the authenticated participant manages (registered).
     */
    public function index(): JsonResponse
    {
        $teams = auth('api')->user()
            ->managedTeams()
            ->with(['event', 'players', 'documents'])
            ->latest('registered_at')
            ->get();

        return ApiResponse::success(TeamResource::collection($teams));
    }
}
