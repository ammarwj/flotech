<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
| Mounted under /api by bootstrap/app.php. All versioned endpoints live
| inside the v1 group. Feature endpoints are added in later phases.
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return ApiResponse::success([
            'service' => 'flo-event-api',
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ], 'Service healthy');
    });

    // Phase 1+: auth, organizations, events, ... mounted here.
});
