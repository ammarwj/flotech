<?php

use App\Http\Controllers\Api\Admin\FeatureDefinitionController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\Admin\PlanFeatureController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventMediaController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MyTeamController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\Public\PublicEventController;
use App\Http\Controllers\Api\Public\PublicTicketController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TicketCategoryController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\Webhook\MidtransWebhookController;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1) — mounted under /api by bootstrap/app.php
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ApiResponse::success([
        'service' => 'flo-event-api',
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ], 'Service healthy'));

    // Public plan catalog (pricing page).
    Route::get('/plans', fn () => ApiResponse::success(
        PlanResource::collection(
            Plan::with('features')
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('sort_order')
                ->get()
        )
    ));

    // ---- Auth ----
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->name('verification.verify')
            ->middleware('signed');

        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend']);
        });
    });

    // ---- Public event landing & registration ----
    Route::prefix('public/events/{orgSlug}/{eventSlug}')->group(function () {
        Route::get('/', [PublicEventController::class, 'show']);
        Route::post('register', [PublicEventController::class, 'register']);
        Route::get('matches', [PublicEventController::class, 'matches']);
        Route::get('standings', [PublicEventController::class, 'standings']);
        Route::get('leaderboard', [PublicEventController::class, 'leaderboard']);
        Route::get('tickets', [PublicTicketController::class, 'categories']);
        Route::post('tickets/purchase', [PublicTicketController::class, 'purchase']);
    });

    // Public e-ticket lookup (by order id).
    Route::get('ticket-orders/{order}', [PublicTicketController::class, 'order']);

    // Presigned upload URL (used by the public registration form too).
    Route::post('uploads/sign', [UploadController::class, 'sign']);
    // Direct image upload (compressed client-side; used for banners & logos).
    Route::post('uploads/image', [UploadController::class, 'image']);

    // ---- Authenticated app ----
    Route::middleware('auth:api')->group(function () {
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);

        // Participant: teams I manage.
        Route::get('my-teams', [MyTeamController::class, 'index']);
        Route::get('my-teams/{team}', [MyTeamController::class, 'show']);
        Route::patch('my-teams/{team}', [MyTeamController::class, 'update']);
        Route::post('my-teams/{team}/withdraw', [MyTeamController::class, 'withdraw']);
        Route::post('my-teams/{team}/pay', [MyTeamController::class, 'pay']);

        Route::middleware('tenant')->prefix('organizations/{organization}')->group(function () {
            Route::get('/', [OrganizationController::class, 'show']);
            Route::patch('plan', [OrganizationController::class, 'assignPlan']);
            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::post('subscriptions/checkout', [SubscriptionController::class, 'checkout']);

            // Event CRUD + registrations management.
            Route::apiResource('events', EventController::class);
            Route::post('events/{event}/publish', [EventController::class, 'publish']);
            Route::get('events/{event}/registrations', [RegistrationController::class, 'index']);
            Route::patch('events/{event}/registrations/{team}', [RegistrationController::class, 'updateStatus']);

            // Photo albums & sponsor logos.
            Route::get('events/{event}/photos', [EventMediaController::class, 'photos']);
            Route::post('events/{event}/photos', [EventMediaController::class, 'storePhotos']);
            Route::patch('photos/{photo}', [EventMediaController::class, 'updatePhoto']);
            Route::delete('photos/{photo}', [EventMediaController::class, 'destroyPhoto']);
            Route::get('events/{event}/sponsors', [EventMediaController::class, 'sponsors']);
            Route::post('events/{event}/sponsors', [EventMediaController::class, 'storeSponsor']);
            Route::patch('sponsors/{sponsor}', [EventMediaController::class, 'updateSponsor']);
            Route::delete('sponsors/{sponsor}', [EventMediaController::class, 'destroySponsor']);

            // Schedule, results & standings (Sprint 2B).
            Route::post('events/{event}/schedule', [MatchController::class, 'generate']);
            Route::post('events/{event}/draw', [MatchController::class, 'drawGroups']);
            Route::get('events/{event}/knockout-plan', [MatchController::class, 'knockoutPlan']);
            Route::post('events/{event}/knockout', [MatchController::class, 'generateKnockout']);
            Route::get('events/{event}/matches', [MatchController::class, 'index']);
            Route::get('events/{event}/standings', [MatchController::class, 'standings']);
            Route::get('events/{event}/leaderboard', [MatchController::class, 'leaderboard']);
            Route::patch('matches/{match}', [MatchController::class, 'updateResult']);
            Route::patch('matches/{match}/schedule', [MatchController::class, 'updateSchedule']);
            Route::patch('matches/{match}/confirm', [MatchController::class, 'confirmResult']);
            Route::get('matches/{match}/stats', [MatchController::class, 'matchStats']);
            Route::put('matches/{match}/stats', [MatchController::class, 'saveMatchStats']);

            // Tickets & check-in (Phase 3).
            Route::get('events/{event}/ticket-categories', [TicketCategoryController::class, 'index']);
            Route::post('events/{event}/ticket-categories', [TicketCategoryController::class, 'store']);
            Route::patch('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'update']);
            Route::delete('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'destroy']);
            Route::get('events/{event}/ticket-report', [ScanController::class, 'report']);
            Route::post('events/{event}/scan', [ScanController::class, 'checkIn']);
        });

        // ---- SaaS Super Admin ----
        Route::middleware('superadmin')->prefix('admin')->group(function () {
            Route::apiResource('plans', PlanController::class);
            Route::put('plans/{plan}/features', [PlanFeatureController::class, 'sync']);
            Route::apiResource('feature-definitions', FeatureDefinitionController::class)
                ->parameters(['feature-definitions' => 'feature_definition'])
                ->except(['show']);
        });
    });

    // ---- Webhooks (signature-verified inside controller) ----
    Route::post('webhooks/midtrans', [MidtransWebhookController::class, 'handle']);
});
