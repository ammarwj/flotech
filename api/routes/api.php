<?php

use App\Http\Controllers\Api\Admin\ActiveSessionController;
use App\Http\Controllers\Api\Admin\ConfigOptionController;
use App\Http\Controllers\Api\Admin\FaqController;
use App\Http\Controllers\Api\Admin\FeatureDefinitionController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\Admin\PlanFeatureController;
use App\Http\Controllers\Api\Admin\PlatformSettingController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\Admin\SportController;
use App\Http\Controllers\Api\Admin\TestimonialController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\ViewStatController as AdminViewStatController;
use App\Http\Controllers\Api\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Api\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CertificateTemplateController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventMediaController;
use App\Http\Controllers\Api\EventViewStatController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MyTeamController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentVerificationController;
use App\Http\Controllers\Api\Public\EventViewController;
use App\Http\Controllers\Api\Public\PublicCertificateController;
use App\Http\Controllers\Api\Public\PublicEventController;
use App\Http\Controllers\Api\Public\PublicOrganizationController;
use App\Http\Controllers\Api\Public\PublicTicketController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RubberController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TicketCategoryController;
use App\Http\Controllers\Api\TicketOrderController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\Webhook\MidtransWebhookController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Resources\FaqResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Faq;
use App\Models\Plan;
use App\Models\Testimonial;
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

    // Landing page content, edited by super_admin under /admin/testimonials & /admin/faqs.
    Route::get('/testimonials', fn () => ApiResponse::success(
        TestimonialResource::collection(
            Testimonial::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
        )
    ));

    Route::get('/faqs', fn () => ApiResponse::success(
        FaqResource::collection(
            Faq::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
        )
    ));

    // Admin-managed vocabulary: sports (+ stat columns), formats, tiebreakers,
    // draw methods, knockout rounds, sponsor tiers. Read by the whole web app.
    Route::get('/catalog', CatalogController::class);

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
            Route::patch('preferences', [AuthController::class, 'updatePreferences']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend']);
        });
    });

    // ---- Public event catalog, landing & registration ----
    Route::get('public/events', [PublicEventController::class, 'index']);
    Route::get('public/organizations/{orgSlug}', [PublicOrganizationController::class, 'show']);

    // Certificate verification — what the QR printed on every certificate opens.
    Route::get('public/certificates/{number}', [PublicCertificateController::class, 'show']);

    Route::prefix('public/events/{orgSlug}/{eventSlug}')->group(function () {
        Route::get('/', [PublicEventController::class, 'show']);

        // Page-view beacon, fired once per tab session by the public page.
        // The only unauthenticated endpoint that writes, hence the throttle —
        // there is deliberately no global one.
        Route::post('view', [EventViewController::class, 'store'])->middleware('throttle:30,1');

        // Signing up needs an account: the team is tied to the manager who filed
        // it, and that link is the only way they reach it in "Tim Saya" later.
        // A team registered anonymously would belong to nobody.
        Route::post('register', [PublicEventController::class, 'register'])->middleware('auth:api');
        // Schedule, standings & leaderboard are per competition category.
        Route::get('categories/{categorySlug}/matches', [PublicEventController::class, 'matches']);
        Route::get('categories/{categorySlug}/standings', [PublicEventController::class, 'standings']);
        Route::get('categories/{categorySlug}/leaderboard', [PublicEventController::class, 'leaderboard']);
        // Player stats of a single fixture, for the match detail dialog.
        Route::get('matches/{match}/stats', [PublicEventController::class, 'matchStats']);
        Route::get('tickets', [PublicTicketController::class, 'categories']);
        Route::post('tickets/purchase', [PublicTicketController::class, 'purchase']);
    });

    // Public e-ticket lookup (by order id).
    Route::get('ticket-orders/{order}', [PublicTicketController::class, 'order']);
    // Transfer receipt for a manual order. Public for the same reason the
    // lookup above is: the buyer never signs up, so the unguessable order id
    // is the credential.
    Route::post('ticket-orders/{order}/proof', [PublicTicketController::class, 'proof']);

    // Presigned upload URL (used by the public registration form too).
    Route::post('uploads/sign', [UploadController::class, 'sign']);
    // Direct image upload (compressed client-side; used for banners & logos).
    Route::post('uploads/image', [UploadController::class, 'image']);

    // ---- Authenticated app ----
    // `track.seen` stamps users.last_seen_at (throttled) so admins can see who is
    // currently accessing the app.
    Route::middleware(['auth:api', 'track.seen'])->group(function () {
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);

        // Participant: teams I manage.
        Route::get('my-teams', [MyTeamController::class, 'index']);
        Route::get('my-teams/{team}', [MyTeamController::class, 'show']);
        Route::patch('my-teams/{team}', [MyTeamController::class, 'update']);
        Route::post('my-teams/{team}/withdraw', [MyTeamController::class, 'withdraw']);
        Route::post('my-teams/{team}/pay', [MyTeamController::class, 'pay']);
        Route::post('my-teams/{team}/proof', [MyTeamController::class, 'proof']);

        Route::middleware('tenant')->prefix('organizations/{organization}')->group(function () {
            Route::get('/', [OrganizationController::class, 'show']);

            // Public page traffic. Aggregates with no personal data in them, so
            // `tenant` is enough — same call as the ticket report below.
            Route::get('view-stats', [EventViewStatController::class, 'organization']);
            Route::get('events/{event}/view-stats', [EventViewStatController::class, 'event']);

            // Billing. Like the wallet, this is money: `tenant` alone would let
            // an `operator` member switch the plan or read the invoices.
            Route::middleware('org.admin')->group(function () {
                // Org profile & branding — the slug is the public URL, so this
                // is owner/admin only too.
                Route::patch('/', [OrganizationController::class, 'update']);
                Route::patch('plan', [OrganizationController::class, 'assignPlan']);
                Route::get('subscriptions', [SubscriptionController::class, 'index']);
                Route::post('subscriptions/checkout', [SubscriptionController::class, 'checkout']);
                Route::post('subscriptions/{subscription}/pay', [SubscriptionController::class, 'pay']);
                Route::get('subscriptions/{subscription}/invoice', [SubscriptionController::class, 'invoice']);
                Route::get('subscriptions/{subscription}/receipt', [SubscriptionController::class, 'receipt']);
            });

            // Event CRUD + registrations management.
            Route::apiResource('events', EventController::class);
            Route::post('events/{event}/publish', [EventController::class, 'publish']);
            // Status moves through its own guarded verb, never the form save.
            Route::patch('events/{event}/status', [EventController::class, 'updateStatus']);
            Route::get('events/{event}/registrations', [RegistrationController::class, 'index']);
            // Offline registration: teams that signed up on paper or over chat.
            Route::post('events/{event}/registrations', [RegistrationController::class, 'store']);
            Route::put('events/{event}/registrations/{team}', [RegistrationController::class, 'update']);
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

            // Schedule, results & standings — scoped to a competition category,
            // since each category (U17, Woman, …) runs its own format.
            Route::post('events/{event}/categories/{category}/schedule', [MatchController::class, 'generate']);
            Route::post('events/{event}/categories/{category}/draw', [MatchController::class, 'drawGroups']);
            Route::get('events/{event}/categories/{category}/knockout-plan', [MatchController::class, 'knockoutPlan']);
            Route::post('events/{event}/categories/{category}/knockout', [MatchController::class, 'generateKnockout']);
            Route::delete('events/{event}/categories/{category}/knockout', [MatchController::class, 'destroyKnockout']);
            Route::get('events/{event}/categories/{category}/matches', [MatchController::class, 'index']);
            // Manual fixture: organizers who already have their own schedule.
            Route::post('events/{event}/categories/{category}/matches', [MatchController::class, 'storeManual']);
            Route::get('events/{event}/categories/{category}/standings', [MatchController::class, 'standings']);
            Route::get('events/{event}/categories/{category}/leaderboard', [MatchController::class, 'leaderboard']);
            Route::patch('matches/{match}', [MatchController::class, 'updateResult']);
            Route::patch('matches/{match}/schedule', [MatchController::class, 'updateSchedule']);
            // Fix a wrong seed in place, instead of rebuilding the whole bracket.
            Route::patch('matches/{match}/teams', [MatchController::class, 'updateTeams']);
            // Signing a result off is what makes it count, so it belongs to
            // whoever runs the org. An operator records; they don't ratify —
            // otherwise saving-as-admin auto-confirms for nothing.
            Route::middleware('org.admin')
                ->patch('matches/{match}/confirm', [MatchController::class, 'confirmResult']);
            // Scheduled / ongoing / cancelled. Finishing still goes through the
            // result endpoint, which is the only one that validates a scoreline.
            Route::patch('matches/{match}/status', [MatchController::class, 'updateStatus']);
            Route::delete('matches/{match}', [MatchController::class, 'destroy']);
            Route::get('matches/{match}/stats', [MatchController::class, 'matchStats']);
            Route::put('matches/{match}/stats', [MatchController::class, 'saveMatchStats']);

            // Partai of a squad tie (badminton beregu & co). The tie's scoreline
            // is rolled up from these, never posted to matches/{match} directly.
            Route::get('matches/{match}/rubbers', [RubberController::class, 'index']);
            Route::put('matches/{match}/rubbers', [RubberController::class, 'sync']);
            Route::patch('rubbers/{rubber}', [RubberController::class, 'update']);

            // Tickets & check-in (Phase 3).
            Route::get('events/{event}/ticket-categories', [TicketCategoryController::class, 'index']);
            Route::post('events/{event}/ticket-categories', [TicketCategoryController::class, 'store']);
            Route::patch('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'update']);
            Route::delete('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'destroy']);
            Route::get('events/{event}/ticket-report', [ScanController::class, 'report']);
            Route::post('events/{event}/scan', [ScanController::class, 'checkIn']);

            // Certificates. Gated on `certificate_generator` inside the
            // controllers (and `certificate_email` for the sending routes), the
            // same way ticketing gates on `qr_tickets`.
            Route::get('certificate-fields', [CertificateTemplateController::class, 'fields']);
            Route::get('certificate-templates', [CertificateTemplateController::class, 'index']);
            Route::post('certificate-templates', [CertificateTemplateController::class, 'store']);
            Route::patch('certificate-templates/{template}', [CertificateTemplateController::class, 'update']);
            Route::delete('certificate-templates/{template}', [CertificateTemplateController::class, 'destroy']);

            Route::get('certificates', [CertificateController::class, 'index']);
            Route::get('events/{event}/certificate-recipients', [CertificateController::class, 'recipients']);
            Route::post('events/{event}/certificates', [CertificateController::class, 'generate']);
            Route::get('certificates/{certificate}/download', [CertificateController::class, 'download']);
            Route::post('certificates/{certificate}/send', [CertificateController::class, 'send']);
            Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy']);

            // Buyer list. Narrowed to owner/admin — the rows carry buyer
            // contact details, unlike the aggregate report above.
            Route::middleware('org.admin')->get(
                'events/{event}/ticket-orders',
                [TicketOrderController::class, 'index'],
            );

            // Manual-transfer verification. Approving a receipt issues a valid
            // ticket on someone's say-so, so it is owner/admin only — a gate
            // operator must not be able to.
            Route::middleware('org.admin')->group(function () {
                Route::get('events/{event}/payments', [PaymentVerificationController::class, 'index']);
                Route::post('events/{event}/payments/tickets/{order}/approve', [PaymentVerificationController::class, 'approveTicket']);
                Route::post('events/{event}/payments/tickets/{order}/reject', [PaymentVerificationController::class, 'rejectTicket']);
                Route::post('events/{event}/payments/teams/{team}/approve', [PaymentVerificationController::class, 'approveTeam']);
                Route::post('events/{event}/payments/teams/{team}/reject', [PaymentVerificationController::class, 'rejectTeam']);
            });

            // Wallet & payouts (Phase 4). Money endpoints are narrowed to the
            // owner/admin — `tenant` alone would also admit gate operators.
            Route::middleware('org.admin')->group(function () {
                Route::get('wallet', [WalletController::class, 'show']);
                Route::get('wallet/transactions', [WalletController::class, 'transactions']);

                Route::get('bank-accounts', [BankAccountController::class, 'index']);
                Route::post('bank-accounts', [BankAccountController::class, 'store']);
                Route::patch('bank-accounts/{bankAccount}', [BankAccountController::class, 'update']);
                Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

                Route::get('withdrawals', [WithdrawalController::class, 'index']);
                Route::post('withdrawals', [WithdrawalController::class, 'store']);
                Route::delete('withdrawals/{withdrawal}', [WithdrawalController::class, 'cancel']);
            });
        });

        // ---- SaaS Super Admin ----
        Route::middleware('superadmin')->prefix('admin')->group(function () {
            Route::apiResource('plans', PlanController::class);
            Route::put('plans/{plan}/features', [PlanFeatureController::class, 'sync']);
            Route::apiResource('feature-definitions', FeatureDefinitionController::class)
                ->parameters(['feature-definitions' => 'feature_definition'])
                ->except(['show']);

            // Public page traffic across the whole platform.
            Route::get('view-stats', [AdminViewStatController::class, 'index']);
            Route::get('view-stats/organizations', [AdminViewStatController::class, 'organizations']);
            Route::get('view-stats/events', [AdminViewStatController::class, 'events']);

            // Landing page content.
            Route::apiResource('testimonials', TestimonialController::class)->except(['show']);
            Route::apiResource('faqs', FaqController::class)->except(['show']);

            // Catalog administration.
            Route::get('engines', [ConfigOptionController::class, 'engines']);
            Route::put('sports/{sport}/stats', [SportController::class, 'syncStats']);
            Route::put('sports/{sport}/positions', [SportController::class, 'syncPositions']);
            Route::apiResource('sports', SportController::class)->except(['show']);
            Route::apiResource('config-options', ConfigOptionController::class)
                ->parameters(['config-options' => 'config_option'])
                ->except(['show']);

            // Payout queue: the platform holds every buyer's payment, so an
            // admin transfers each organizer's share by hand and records it.
            Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
            Route::get('withdrawals/{withdrawal}', [AdminWithdrawalController::class, 'show']);
            Route::patch('withdrawals/{withdrawal}/process', [AdminWithdrawalController::class, 'process']);
            Route::patch('withdrawals/{withdrawal}/complete', [AdminWithdrawalController::class, 'complete']);
            Route::patch('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject']);

            // Collected payments + refunds (a refund reverses the wallet credit).
            Route::get('payments', [AdminRefundController::class, 'index']);
            Route::post('ticket-orders/{ticketOrder}/refund', [AdminRefundController::class, 'refundTicketOrder']);
            Route::post('teams/{team}/refund', [AdminRefundController::class, 'refundTeam']);

            Route::get('wallets', [AdminWalletController::class, 'index']);
            Route::post('wallets/{wallet}/adjust', [AdminWalletController::class, 'adjust']);

            // Payout policy (minimum, admin fee, hold days) — editable without
            // a deploy; config/wallet.php holds the defaults.
            Route::get('settings', [PlatformSettingController::class, 'index']);
            Route::put('settings', [PlatformSettingController::class, 'update']);

            // Who is logged in / currently accessing the app.
            Route::get('active-sessions', [ActiveSessionController::class, 'index']);

            // Platform user management (list/search, role change, verify, delete).
            Route::get('users', [AdminUserController::class, 'index']);
            Route::patch('users/{user}', [AdminUserController::class, 'update']);
            Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
            // "Login as": mints an access token acting as this user (no refresh
            // cookie, so the admin's own session survives). Ordinary users only.
            Route::post('users/{user}/impersonate', [AdminUserController::class, 'impersonate']);
        });
    });

    // ---- Webhooks (signature-verified inside controller) ----
    Route::post('webhooks/midtrans', [MidtransWebhookController::class, 'handle']);
});
