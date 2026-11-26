<?php

use App\Http\Controllers\Api\Admin\ActiveSessionController;
use App\Http\Controllers\Api\Admin\BlogCategoryController;
use App\Http\Controllers\Api\Admin\BlogPostController;
use App\Http\Controllers\Api\Admin\ConfigOptionController;
use App\Http\Controllers\Api\Admin\DiscountCodeController;
use App\Http\Controllers\Api\Admin\DomainOrderController as AdminDomainOrderController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\Admin\FaqController;
use App\Http\Controllers\Api\Admin\FeatureDefinitionController;
use App\Http\Controllers\Api\Admin\LandingStatController;
use App\Http\Controllers\Api\Admin\PaymentStatController as AdminPaymentStatController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\Admin\PlanFeatureController;
use App\Http\Controllers\Api\Admin\PlanOrderController as AdminPlanOrderController;
use App\Http\Controllers\Api\Admin\PlatformSettingController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\Admin\RefundPayoutController as AdminRefundPayoutController;
use App\Http\Controllers\Api\Admin\SiteSettingController;
use App\Http\Controllers\Api\Admin\SportController;
use App\Http\Controllers\Api\Admin\StatController as AdminStatController;
use App\Http\Controllers\Api\Admin\TestimonialController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\ViewStatController as AdminViewStatController;
use App\Http\Controllers\Api\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Api\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BibController;
use App\Http\Controllers\Api\BibTemplateController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CertificateTemplateController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventDiscountCodeController;
use App\Http\Controllers\Api\EventMediaController;
use App\Http\Controllers\Api\EventOperatorController;
use App\Http\Controllers\Api\EventOperatorInviteController;
use App\Http\Controllers\Api\EventPersonnelController;
use App\Http\Controllers\Api\EventViewStatController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\IdCardController;
use App\Http\Controllers\Api\IdCardTemplateController;
use App\Http\Controllers\Api\LineupApprovalController;
use App\Http\Controllers\Api\LineupSheetController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchReportController;
use App\Http\Controllers\Api\MyRaceEntryController;
use App\Http\Controllers\Api\MyTeamController;
use App\Http\Controllers\Api\MyTeamMatchController;
use App\Http\Controllers\Api\OfficiatingController;
use App\Http\Controllers\Api\OfficiatingMatchController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentVerificationController;
use App\Http\Controllers\Api\PlanOrderController;
use App\Http\Controllers\Api\Public\DomainController;
use App\Http\Controllers\Api\Public\EventViewController;
use App\Http\Controllers\Api\Public\PublicBlogController;
use App\Http\Controllers\Api\Public\PublicCertificateController;
use App\Http\Controllers\Api\Public\PublicEventController;
use App\Http\Controllers\Api\Public\PublicIdCardController;
use App\Http\Controllers\Api\Public\PublicOrganizationController;
use App\Http\Controllers\Api\Public\PublicPaymentController;
use App\Http\Controllers\Api\Public\PublicRaceController;
use App\Http\Controllers\Api\Public\PublicTicketController;
use App\Http\Controllers\Api\PublicStatController;
use App\Http\Controllers\Api\RaceCategoryController;
use App\Http\Controllers\Api\RaceEntryController;
use App\Http\Controllers\Api\RacePeriodController;
use App\Http\Controllers\Api\RaceRefundController;
use App\Http\Controllers\Api\RaceScanController;
use App\Http\Controllers\Api\RefundPayoutController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RubberController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\StandingAdjustmentController;
use App\Http\Controllers\Api\TeamAlbumController;
use App\Http\Controllers\Api\TicketCategoryController;
use App\Http\Controllers\Api\TicketOrderController;
use App\Http\Controllers\Api\TicketPosterController;
use App\Http\Controllers\Api\TicketSaleController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\Webhook\MidtransWebhookController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Resources\FaqResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\PublicSiteSettingResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Faq;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Support\ApiResponse;
use App\Support\DomainAddons;
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

    // Domain add-on price list. Public for the same reason /plans is: it is
    // priced on the pricing page, before anyone has logged in. Read through
    // DomainAddons so the figure quoted here is the figure the till charges.
    Route::get('/domain-addons', fn () => ApiResponse::success(DomainAddons::catalogue()));

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

    // Contact details and social profiles in the footer, which renders on every
    // public page — not just the landing one.
    Route::get('/site-settings', fn () => ApiResponse::success(
        new PublicSiteSettingResource(SiteSetting::current())
    ));

    // Landing page proof counters (turnamen, tim, tiket, laga).
    Route::get('/stats', PublicStatController::class);

    // Blog, written by super_admin under /admin/blog. Only published articles
    // leave here — drafts and scheduled ones are filtered by scopePublished(),
    // including on the detail route, whose slug is guessable.
    //
    // `categories` sits above `{slug}` so the literal segment is never
    // swallowed by the placeholder.
    Route::get('/blog', [PublicBlogController::class, 'index']);
    Route::get('/blog/categories', [PublicBlogController::class, 'categories']);
    Route::get('/blog/{slug}', [PublicBlogController::class, 'show']);

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
            // Change my own password (asks for the current one).
            Route::patch('password', [AuthController::class, 'updatePassword']);
        });
    });

    // ---- Public event catalog, landing & registration ----
    Route::get('public/events', [PublicEventController::class, 'index']);

    // Hostname -> event routing table, read by the Next middleware before any
    // user exists. Every domain in it is already public by definition.
    Route::get('public/domains', [DomainController::class, 'index']);

    Route::get('public/organizations/{orgSlug}', [PublicOrganizationController::class, 'show']);

    // Certificate verification — what the QR printed on every certificate opens.
    Route::get('public/certificates/{number}', [PublicCertificateController::class, 'show']);

    // ID card verification — what the QR printed on a card opens. Its own route
    // rather than one endpoint branching on the number's prefix: the two prove
    // different documents and print different things, and a shared page would
    // be a second reader of which kind it was handed.
    Route::get('public/id-cards/{number}', [PublicIdCardController::class, 'show']);

    // Fee breakdown per channel, for the picker shown before any checkout
    // (tickets, registrations, plan orders) creates its Snap token.
    Route::get('public/payment-channels', [PublicPaymentController::class, 'channels']);

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
        // What a discount code would do to this registration's price, for both
        // participant rails. Behind auth:api like the two register routes, for
        // the same reason: the once-per-user rule is keyed to the account.
        Route::post('discount-preview', [PublicEventController::class, 'discountPreview'])
            ->middleware('auth:api');
        // Schedule, standings & leaderboard are per competition category.
        Route::get('categories/{categorySlug}/matches', [PublicEventController::class, 'matches']);
        Route::get('categories/{categorySlug}/standings', [PublicEventController::class, 'standings']);
        Route::get('categories/{categorySlug}/leaderboard', [PublicEventController::class, 'leaderboard']);
        // Who may not take the field, and why. Public for the same reason the
        // schedule is: the squad facing them needs it as much as the panitia.
        Route::get('categories/{categorySlug}/discipline', [PublicEventController::class, 'discipline']);
        // Who finished first, second and third — every category of the event in
        // one payload, not per category like the four above it. "Siapa juaranya"
        // is asked of the whole event, and a podium is three rows, so splitting
        // it would cost a round trip per category to assemble what one answer
        // already is.
        Route::get('podium', [PublicEventController::class, 'podium']);
        // Player stats of a single fixture, for the match detail dialog.
        Route::get('matches/{match}/stats', [PublicEventController::class, 'matchStats']);
        // One fixture on its own, for the scoreboard screen that polls it while
        // it is being played. Below the stats route so the literal segment is
        // never swallowed by the {match} placeholder.
        Route::get('matches/{match}', [PublicEventController::class, 'match']);
        Route::get('tickets', [PublicTicketController::class, 'categories']);
        Route::post('tickets/purchase', [PublicTicketController::class, 'purchase']);

        // Fun run. `race` lists what is on sale with the price windows it
        // belongs to, in one payload — the page prints a price and the date it
        // changes in the same sentence, and two requests would let them
        // disagree across a boundary.
        Route::get('race', [PublicRaceController::class, 'categories']);
        // Entering needs an account, the same rule team registration follows:
        // the entry is tied to whoever filed it, and that link is the only way
        // they reach it again later.
        Route::post('race/register', [PublicRaceController::class, 'register'])->middleware('auth:api');
    });

    // Public fun run entry lookup (by entry id). Public for the reason the
    // e-ticket lookup below is: the unguessable id is the credential, and in
    // this phase the receipt reached from here IS what the runner shows at the
    // start line — a login would lock them out of their only proof.
    Route::get('race-entries/{raceEntry}', [PublicRaceController::class, 'entry']);
    Route::post('race-entries/{raceEntry}/proof', [PublicRaceController::class, 'proof']);
    // Asking for the entry fee back. Public for the same reason as the three
    // doors around it, written above and in the controller: a runner may have
    // arrived from an email and never signed up, and `user_id` is nullable
    // precisely because an entry outlives the account that filed it.
    Route::post('race-entries/{raceEntry}/refund-request', [PublicRaceController::class, 'requestRefund']);
    // Moving to another category. Public for the same reason as the doors
    // around it, and the preview shares `quote()` with the create below so no
    // option is offered on screen and then refused at the till.
    Route::get('race-entries/{raceEntry}/transfer-options', [PublicRaceController::class, 'transferOptions']);
    Route::post('race-entries/{raceEntry}/transfer', [PublicRaceController::class, 'transfer']);
    // Keyed on the TRANSFER, not the entry: the row being paid for is the
    // transfer, and routing through the entry would need a second lookup rule
    // ("the open one") that the guard refusing a second move already owns.
    // Finishing — or giving up on — a move that was started and left unpaid.
    // Without these two a runner who closed the Midtrans tab had no way to pay
    // and no way to release the place the move was holding: the hourly sweep
    // only reaches the manual rail. `pay` mints a FRESH Midtrans order id and
    // keeps the invoice number, the split EventPlanOrderService::pay() makes.
    Route::post('race-transfers/{transfer}/pay', [PublicRaceController::class, 'payTransfer']);
    Route::post('race-transfers/{transfer}/cancel', [PublicRaceController::class, 'cancelTransfer']);
    Route::post('race-transfers/{transfer}/proof', [PublicRaceController::class, 'transferProof']);
    // A top-up is its own bill, so it has its own two documents — the entry's
    // original receipt keeps saying exactly what it said, which is the whole
    // reason `race_entries.price` is never rewritten.
    Route::get('race-transfers/{transfer}/invoice', [PublicRaceController::class, 'transferInvoice']);
    Route::get('race-transfers/{transfer}/receipt', [PublicRaceController::class, 'transferReceipt']);
    Route::get('race-entries/{raceEntry}/invoice', [PublicRaceController::class, 'invoice']);
    Route::get('race-entries/{raceEntry}/receipt', [PublicRaceController::class, 'receipt']);
    // The e-BIB, public for the same reason the two documents above are: the
    // entry's unguessable id is the credential, and this sheet is what gets the
    // runner through the race-pack desk.
    Route::get('race-entries/{raceEntry}/bib', [PublicRaceController::class, 'bib']);

    // Public e-ticket lookup (by order id).
    Route::get('ticket-orders/{order}', [PublicTicketController::class, 'order']);
    // Transfer receipt for a manual order. Public for the same reason the
    // lookup above is: the buyer never signs up, so the unguessable order id
    // is the credential.
    Route::post('ticket-orders/{order}/proof', [PublicTicketController::class, 'proof']);
    // The buyer's invoice & receipt. Public for the same reason — a ticket
    // buyer never signs up, so auth here would lock them out of their own
    // documents.
    Route::get('ticket-orders/{order}/invoice', [PublicTicketController::class, 'invoice']);
    Route::get('ticket-orders/{order}/receipt', [PublicTicketController::class, 'receipt']);

    // Event operator invite preview — unauthenticated, since the page has to
    // render (and decide between a login/register CTA and the "Terima"
    // button) before anyone is signed in. The token is the only credential;
    // accepting it below is what actually proves the account.
    Route::get('invites/{token}', [EventOperatorInviteController::class, 'show']);

    // Presigned upload URL (used by the public registration form too).
    Route::post('uploads/sign', [UploadController::class, 'sign']);
    // Direct image upload (compressed client-side; used for banners & logos).
    Route::post('uploads/image', [UploadController::class, 'image']);
    // Registration documents. Unlike uploads/sign it validates type and size,
    // and re-encodes images to WebP — the public registration form posts here.
    Route::post('uploads/document', [UploadController::class, 'document']);

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
        // The manager's own billing documents for the registration fee.
        Route::get('my-teams/{team}/invoice', [MyTeamController::class, 'invoice']);
        Route::get('my-teams/{team}/receipt', [MyTeamController::class, 'receipt']);
        // Same printable player album the organizer can print, scoped by session.
        Route::get('my-teams/{team}/album', [MyTeamController::class, 'album']);

        // Participant: fun run entries I filed. A list and nothing more — every
        // door onto a single entry (proof, invoice, receipt, QR) already lives
        // behind its unguessable id under `race-entries/*`, public for the
        // reason written there. See MyRaceEntryController.
        Route::get('my-race-entries', [MyRaceEntryController::class, 'index']);

        // Event operator invites, addressed to this account's own email.
        // `accept` is the proof-of-email step — EventOperatorService::accept()
        // still re-checks the address, this middleware only proves someone is
        // logged in at all. Outside organizations/{organization}: accepting
        // one may be the first organizer-side thing this account ever does.
        Route::post('invites/{token}/accept', [EventOperatorInviteController::class, 'accept']);
        Route::get('my-operator-invites', [EventOperatorInviteController::class, 'pending']);
        // Landing page for an account that holds no organization_members seat
        // at all but operates one or more events — see
        // docs/event-operator-design.md section 9.
        Route::get('my-operator-events', [EventOperatorInviteController::class, 'myEvents']);

        // The manager's team sheet, handed to the referee before kick-off.
        // Same tier and same `scope()` as the rest of my-teams/*: a manager is an
        // ordinary participant account, and managedTeams() is the only thing that
        // proves the team is theirs.
        Route::get('my-teams/{team}/matches', [MyTeamMatchController::class, 'index']);
        Route::get('my-teams/{team}/matches/{match}/lineup', [MyTeamMatchController::class, 'lineup']);
        Route::put('my-teams/{team}/matches/{match}/lineup', [MyTeamMatchController::class, 'saveLineup']);
        Route::post('my-teams/{team}/matches/{match}/lineup/submit', [MyTeamMatchController::class, 'submitLineup']);

        // ---- Officiating: referees and match staff ----
        // Deliberately a sibling of organizations/{organization}, not a branch
        // inside it. A task account holds no organization_members row, so
        // `tenant` would refuse it — and that refusal is the feature: the whole
        // organizer surface (wallet, billing, deleting the event) is closed to
        // the crew structurally rather than by anyone remembering to hide it.
        //
        // `password.rotated` stacks here and nowhere near auth/*: these accounts
        // are mailed a default password, and this is the group it must stop
        // opening. The endpoints that fix the situation live outside this group.
        Route::middleware('password.rotated')->group(function () {
            // Outside the event prefix: the landing page has no event id yet.
            Route::get('officiating/events', [OfficiatingController::class, 'index']);

            Route::middleware('event.personnel')->prefix('officiating/events/{event}')->group(function () {
                Route::get('/', [OfficiatingController::class, 'show']);
                Route::get('categories/{category}/matches', [OfficiatingController::class, 'matches']);
                // Read by both halves of the crew: staff type the cards, the
                // referee has to know who may not take the field. Same payload
                // as the organizer's and the public one, same service.
                Route::get('categories/{category}/discipline', [OfficiatingController::class, 'discipline']);

                // The role-gated halves stack *after* event.personnel, which is
                // what puts the row they read on the request.
                Route::middleware('event.staff')->group(function () {
                    Route::get('matches/{match}/stats', [OfficiatingMatchController::class, 'matchStats']);
                    Route::put('matches/{match}/stats', [OfficiatingMatchController::class, 'saveMatchStats']);
                    Route::patch('matches/{match}', [OfficiatingMatchController::class, 'updateResult']);
                    // Jam pertandingan — `event.staff`, bukan `event.referee`:
                    // yang memegang stopwatch adalah petugas yang juga mengetik
                    // skor; wasit hanya mengesahkan hasilnya.
                    Route::patch('matches/{match}/clock', [OfficiatingMatchController::class, 'updateClock']);
                    // The sheet for the IP table. Refuses until the referee has
                    // signed off *both* sides; the organizer's twin below is the
                    // same action, so the gate is written once.
                    Route::get('matches/{match}/lineup-sheet', [LineupSheetController::class, 'download']);
                    // The report filed once the fixture is over. Its twin on the
                    // organizer's side below is the same action, so "selesai dan
                    // ada skornya" is written once.
                    Route::get('matches/{match}/report', [MatchReportController::class, 'download']);
                });

                // The referee's half. Same stacking, same reason: `event.personnel`
                // above put the row this one reads on the request.
                Route::middleware('event.referee')->group(function () {
                    Route::get('matches/{match}/lineups', [LineupApprovalController::class, 'index']);
                    // Addressed by lineup, not by match+team: the two sheets of a
                    // fixture are answered one at a time, and the id is what says
                    // which one. Scoped back to this event inside the controller.
                    Route::post('lineups/{lineup}/approve', [LineupApprovalController::class, 'approve']);
                    Route::post('lineups/{lineup}/reject', [LineupApprovalController::class, 'reject']);
                });
            });
        });

        Route::middleware('tenant')->prefix('organizations/{organization}')->group(function () {
            Route::get('/', [OrganizationController::class, 'show']);

            // Public page traffic. Aggregates with no personal data in them, so
            // `tenant` is enough — same call as the ticket report below.
            Route::get('view-stats', [EventViewStatController::class, 'organization']);
            Route::get('events/{event}/view-stats', [EventViewStatController::class, 'event']);

            // Security deposit monitoring. Derived, read-only, and no money moves
            // through the platform for it — same reasoning as the discipline read
            // below: `tenant` alone is enough, the operator has as much reason to
            // see it as the organizer.
            Route::get('events/{event}/deposits', [DepositController::class, 'index']);

            // Billing. Like the wallet, this is money: `tenant` alone would let
            // an `operator` member switch the plan or read the invoices.
            Route::middleware('org.admin')->group(function () {
                // Org profile & branding — the slug is the public URL, so this
                // is owner/admin only too.
                Route::patch('/', [OrganizationController::class, 'update']);
                Route::get('plan-orders', [PlanOrderController::class, 'index']);
                Route::post('plan-orders/checkout', [PlanOrderController::class, 'checkout']);
                // Declared ahead of every `plan-orders/{planOrder}/...` route:
                // a literal segment registered after them is swallowed by the
                // binding and resolves as an order id.
                Route::post('plan-orders/discount-preview', [PlanOrderController::class, 'discountPreview']);
                // Buy a domain for an event that already exists — the other half
                // of "an add-on rides along with the plan". Literal segments, so
                // they are declared ahead of the {planOrder} routes below for
                // the reason spelled out above.
                Route::post('plan-orders/domain-preview', [PlanOrderController::class, 'domainPreview']);
                Route::post('plan-orders/domain-checkout', [PlanOrderController::class, 'domainCheckout']);
                Route::post('plan-orders/{planOrder}/pay', [PlanOrderController::class, 'pay']);
                // Upgrade only — there is no downgrade route, and the absence is
                // enforced in PlanGate::planCovers() rather than by leaving one out.
                Route::get('plan-orders/{planOrder}/upgrade-options', [PlanOrderController::class, 'upgradeOptions']);
                Route::post('plan-orders/{planOrder}/upgrade', [PlanOrderController::class, 'upgrade']);
                // Transfer receipt for a manual plan payment (gateway is off).
                Route::post('plan-orders/{planOrder}/proof', [PlanOrderController::class, 'proof']);
                Route::get('plan-orders/{planOrder}/invoice', [PlanOrderController::class, 'invoice']);
                Route::get('plan-orders/{planOrder}/receipt', [PlanOrderController::class, 'receipt']);

                // Refunds the platform owes this organizer, and the ones they
                // owe onward to a participant. Behind org.admin with the wallet
                // for the same reason: marking a refund forwarded is a
                // statement about money having been sent, which the participant
                // reads and acts on. This half can only touch `forwarded_*` —
                // the platform's own transfer columns have exactly one writer,
                // in Admin\RefundPayoutController.
                Route::get('refund-payouts', [RefundPayoutController::class, 'index']);
                Route::patch('refund-payouts/{refundPayout}/forward', [RefundPayoutController::class, 'forward']);

                // Event operators: who an owner/admin has handed a slice of
                // this event's organizer surface to. org.admin because
                // inviting one IS granting access, same tier as the wallet
                // and plan-orders beside it — a gate `operator` member must
                // not be able to invite another operator.
                Route::get('events/{event}/operators', [EventOperatorController::class, 'index']);
                Route::get('events/{event}/operator-invites', [EventOperatorController::class, 'invites']);
                Route::post('events/{event}/operator-invites', [EventOperatorController::class, 'invite']);
                Route::post('operator-invites/{invite}/resend', [EventOperatorController::class, 'resend']);
                Route::delete('operator-invites/{invite}', [EventOperatorController::class, 'revoke']);
                Route::patch('operators/{operator}', [EventOperatorController::class, 'updatePermissions']);
                Route::delete('operators/{operator}', [EventOperatorController::class, 'remove']);
            });

            // Event CRUD + registrations management.
            //
            // Creating an event now spends a credit the organization paid for,
            // with no undo — so it joins the wallet and billing endpoints behind
            // org.admin. `tenant` on its own admits `operator` members (the gate
            // scanners), who have no business committing the org's money.
            Route::post('events', [EventController::class, 'store'])->middleware('org.admin');
            // `show` is excluded here and registered instead under `event.access`
            // below, outside any `event.perm.*` category — every operator, no
            // matter which single category they hold, needs the event's own
            // name/status/dates to render a page header or breadcrumb, and that
            // is read-only. `update`/`destroy` stay here, behind plain `tenant`
            // only: an operator holds no `organization_members` row at all, so
            // `tenant` refuses them before the controller is ever reached — no
            // change needed to keep those two administrative, not operational.
            Route::apiResource('events', EventController::class)->except(['store', 'show']);
            Route::post('events/{event}/publish', [EventController::class, 'publish']);
            // Status moves through its own guarded verb, never the form save.
            Route::patch('events/{event}/status', [EventController::class, 'updateStatus']);
            // What the registration form asks entrants for. Its own screen, not
            // a block in the event form: it is decided once, before registration
            // opens, and the event form is already a page of its own. `tenant`
            // like the form save — this is configuration, not a money surface.
            Route::get('events/{event}/registration-form', [EventController::class, 'registrationForm']);
            Route::put('events/{event}/registration-form', [EventController::class, 'syncRegistrationForm']);
            // Downloads of this event's data. Behind org.admin with the rest of
            // the data surface, and gated on the event's own `export_data` —
            // this route exists precisely because gating /registrations instead
            // would break the page that reads it.
            Route::get('events/{event}/exports/{kind}', [ExportController::class, 'show'])
                ->middleware('org.admin');

            // Certificate/ID-card/bib TEMPLATE CRUD, and the org-scoped
            // id-cards/bibs print history — deliberately NOT moved to
            // `event.perm.documents` below. Templates and cross-event history
            // are org-scoped rows an operator (who holds no org membership at
            // all) has no business reaching; only a particular event's own
            // printing is event-keyed and belongs to that group.
            Route::get('certificate-fields', [CertificateTemplateController::class, 'fields']);
            Route::get('certificate-templates', [CertificateTemplateController::class, 'index']);
            Route::post('certificate-templates', [CertificateTemplateController::class, 'store']);
            Route::patch('certificate-templates/{template}', [CertificateTemplateController::class, 'update']);
            Route::delete('certificate-templates/{template}', [CertificateTemplateController::class, 'destroy']);
            Route::get('id-card-fields', [IdCardTemplateController::class, 'fields']);
            Route::get('id-card-templates', [IdCardTemplateController::class, 'index']);
            Route::post('id-card-templates', [IdCardTemplateController::class, 'store']);
            Route::patch('id-card-templates/{template}', [IdCardTemplateController::class, 'update']);
            Route::delete('id-card-templates/{template}', [IdCardTemplateController::class, 'destroy']);
            Route::get('bib-fields', [BibTemplateController::class, 'fields']);
            Route::get('bib-templates', [BibTemplateController::class, 'index']);
            Route::post('bib-templates', [BibTemplateController::class, 'store']);
            Route::patch('bib-templates/{template}', [BibTemplateController::class, 'update']);
            Route::delete('bib-templates/{template}', [BibTemplateController::class, 'destroy']);

            // Signing a result off is what makes it count, so it belongs to
            // whoever runs the org. An operator records; they don't ratify —
            // otherwise saving-as-admin auto-confirms for nothing. Stays
            // `tenant` + `org.admin` here, deliberately NOT moved to the
            // `event.perm.scheduling` group below: an event operator must
            // NEVER be able to reach this route under any permission.
            Route::middleware('org.admin')
                ->patch('matches/{match}/confirm', [MatchController::class, 'confirmResult']);

            // Buyer list. Narrowed to owner/admin — the rows carry buyer
            // contact details, unlike the aggregate report above.
            Route::middleware('org.admin')->get(
                'events/{event}/ticket-orders',
                [TicketOrderController::class, 'index'],
            );

            // Runner list, and the two corrections an organizer may make to one
            // row. Narrowed the same way the buyer list above is, and for the
            // same reason: these rows carry a runner's email, phone and
            // emergency contact — more contact detail than any other
            // participant list in the app, not less. The two writes belong here
            // for a second reason on top of that: cancelling frees a place and
            // editing rewrites somebody's identity documents, neither of which
            // is a check-in desk's job.
            //
            // There is no `destroy`, deliberately — see RaceEntryController.
            Route::middleware('org.admin')->group(function () {
                Route::get('events/{event}/race-entries', [RaceEntryController::class, 'index']);
                Route::patch('events/{event}/race-entries/{raceEntry}', [RaceEntryController::class, 'update']);
                Route::post('events/{event}/race-entries/{raceEntry}/cancel', [RaceEntryController::class, 'cancel']);
            });

            // The refund queue: runners asking for their entry fee back.
            //
            // `org.admin` for the reason the wallet endpoints are — approving
            // reverses a wallet credit and may push the balance negative, which
            // locks the next withdrawal, so a gate operator must not be able to.
            // Its own controller rather than more methods above: that list is
            // the start list, this is a queue of decisions. See
            // RaceRefundController.
            Route::middleware('org.admin')->group(function () {
                Route::get('events/{event}/race-refunds', [RaceRefundController::class, 'index']);
                Route::post('events/{event}/race-refunds/{raceEntry}/approve', [RaceRefundController::class, 'approve']);
                Route::post('events/{event}/race-refunds/{raceEntry}/reject', [RaceRefundController::class, 'reject']);
                // A refund the organizer starts, with nobody having asked —
                // the duplicate-payment case. Beside the two verdicts rather
                // than on the runner list: it is the same money moving through
                // the same service, and the one door for an entry that DID ask
                // is `approve` above (see issue()).
                Route::post('events/{event}/race-refunds/{raceEntry}/issue', [RaceRefundController::class, 'issue']);
            });

            // Manual-transfer verification. Approving a receipt issues a valid
            // ticket on someone's say-so, so it is owner/admin only — a gate
            // operator must not be able to.
            Route::middleware('org.admin')->group(function () {
                Route::get('events/{event}/payments', [PaymentVerificationController::class, 'index']);
                // Before the `{order}`-style routes below, or `history` is
                // caught as an id — the same ordering `plan-orders/history`
                // needs. Same gate as the queue: it names which of the org's
                // admins accepted each receipt.
                Route::get('events/{event}/payments/history', [PaymentVerificationController::class, 'history']);
                Route::post('events/{event}/payments/tickets/{order}/approve', [PaymentVerificationController::class, 'approveTicket']);
                Route::post('events/{event}/payments/tickets/{order}/reject', [PaymentVerificationController::class, 'rejectTicket']);
                Route::post('events/{event}/payments/teams/{team}/approve', [PaymentVerificationController::class, 'approveTeam']);
                Route::post('events/{event}/payments/teams/{team}/reject', [PaymentVerificationController::class, 'rejectTeam']);
                Route::post('events/{event}/payments/race-entries/{raceEntry}/approve', [PaymentVerificationController::class, 'approveRaceEntry']);
                Route::post('events/{event}/payments/race-entries/{raceEntry}/reject', [PaymentVerificationController::class, 'rejectRaceEntry']);
                // Category moves. `org.admin` like the four rails beside it,
                // and here the stakes are higher than money: approving one
                // MOVES the runner between categories, releases the place they
                // came from and drops their bib number.
                Route::post('events/{event}/payments/race-transfers/{transfer}/approve', [PaymentVerificationController::class, 'approveRaceTransfer']);
                Route::post('events/{event}/payments/race-transfers/{transfer}/reject', [PaymentVerificationController::class, 'rejectRaceTransfer']);
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

        // ---- Event operator surfaces ----
        // Same URL shape as the `tenant` group above
        // (`organizations/{organization}/events/{event}/...` and
        // `organizations/{organization}/<model>/{id}` for the model-bound
        // block further down) so no frontend call site changes — but
        // `tenant` is deliberately NOT applied to this prefix. An event
        // operator holds no `organization_members` row, so `tenant` alone
        // would refuse them before `event.access` below ever got a chance
        // to recognize them. `{organization}` in the path is accepted and
        // otherwise unused here (every controller resolves its scope from
        // the `event`/`operator` attributes `event.access` sets, via
        // ResolvesEventScope), the same way it is already unused by
        // position in several controllers above.
        //
        // `password.rotated` stacks here for the same reason it stacks on
        // `officiating/*`: an event operator is a mailed-default-password
        // account exactly like a referee, and this is the duty surface it
        // must not open before the password is changed. Safe to apply to the
        // WHOLE group rather than narrowing to operators only — it is a
        // no-op for anyone without `must_change_password` set, which is every
        // org member and every operator who already rotated theirs.
        Route::middleware('password.rotated')->prefix('organizations/{organization}')->group(function () {
        // `event.access` resolves the event and admits either an org member
        // (full access, exactly as `tenant` would have) or an operator
        // (narrowed below by category) — see EventOrOperatorScope. Every
        // route here was, before this feature, a plain `tenant` route; moving
        // it here changes nothing for an org member and ADDS operator access
        // for the one category it declares.
        Route::middleware('event.access')->prefix('events/{event}')->group(function () {
            // Read-only event detail, reachable by an operator holding ANY
            // single category — deliberately outside every `event.perm.*`
            // group below. Almost every operator sub-page needs the event's
            // own name/status/dates/plan for its header or breadcrumb before
            // it can even ask "does this operator hold the category this page
            // needs", and that information is not a privilege: it is what
            // `event.access` itself already proved the caller may see by
            // admitting them at all. `update`/`destroy` are NOT here — those
            // stay on the plain `tenant` route above, administrative rather
            // than operational, exactly like `POST events` staying behind
            // `org.admin`.
            Route::get('/', [EventController::class, 'show']);
            // ---- ticketing ----
            Route::middleware('event.perm.ticketing')->group(function () {
                Route::get('ticket-categories', [TicketCategoryController::class, 'index']);
                Route::post('ticket-categories', [TicketCategoryController::class, 'store']);
                Route::get('ticket-poster', [TicketPosterController::class, 'show']);
                Route::get('ticket-report', [ScanController::class, 'report']);
                Route::get('checkins', [ScanController::class, 'checkins']);
                Route::post('scan', [ScanController::class, 'checkIn']);
                Route::post('ticket-sales', [TicketSaleController::class, 'store']);
            });

            // ---- registration ----
            Route::middleware('event.perm.registration')->group(function () {
                Route::get('registrations', [RegistrationController::class, 'index']);
                Route::post('registrations', [RegistrationController::class, 'store']);
                Route::put('registrations/{team}', [RegistrationController::class, 'update']);
                Route::patch('registrations/{team}', [RegistrationController::class, 'updateStatus']);
                Route::get('registrations/import-template', [RegistrationController::class, 'importTemplate']);
                Route::post('registrations/import', [RegistrationController::class, 'import']);
                Route::get('registrations/{team}/invoice', [RegistrationController::class, 'invoice']);
                Route::get('registrations/{team}/receipt', [RegistrationController::class, 'receipt']);
                Route::get('registrations/album', [TeamAlbumController::class, 'event']);
                Route::get('registrations/{team}/album', [TeamAlbumController::class, 'team']);
                Route::get('race-periods', [RacePeriodController::class, 'index']);
                Route::put('race-periods', [RacePeriodController::class, 'sync']);
                Route::get('race-categories', [RaceCategoryController::class, 'index']);
                Route::post('race-categories', [RaceCategoryController::class, 'store']);
                Route::get('discount-codes', [EventDiscountCodeController::class, 'index']);
                Route::post('discount-codes', [EventDiscountCodeController::class, 'store']);
                Route::post('race-scan', [RaceScanController::class, 'checkIn']);
                Route::get('race-report', [RaceScanController::class, 'report']);
                Route::get('race-checkins', [RaceScanController::class, 'checkins']);
            });

            // ---- scheduling ----
            Route::middleware('event.perm.scheduling')->group(function () {
                Route::post('categories/{category}/schedule', [MatchController::class, 'generate']);
                Route::post('categories/{category}/draw', [MatchController::class, 'drawGroups']);
                Route::get('categories/{category}/swiss', [MatchController::class, 'swissState']);
                Route::post('categories/{category}/swiss/rounds', [MatchController::class, 'generateSwissRound']);
                Route::delete('categories/{category}/swiss/rounds/last', [MatchController::class, 'destroySwissRound']);
                Route::get('categories/{category}/knockout-plan', [MatchController::class, 'knockoutPlan']);
                Route::put('categories/{category}/knockout-plan', [MatchController::class, 'saveKnockoutPlan']);
                Route::delete('categories/{category}/knockout-plan', [MatchController::class, 'destroyKnockoutPlan']);
                Route::post('categories/{category}/knockout-plan/shuffle', [MatchController::class, 'shuffleKnockoutPlan']);
                Route::post('categories/{category}/knockout', [MatchController::class, 'generateKnockout']);
                Route::delete('categories/{category}/knockout', [MatchController::class, 'destroyKnockout']);
                Route::post('categories/{category}/bracket/shuffle', [MatchController::class, 'shuffleBracket']);
                Route::get('categories/{category}/matches', [MatchController::class, 'index']);
                Route::post('categories/{category}/matches', [MatchController::class, 'storeManual']);
                Route::get('categories/{category}/standings', [MatchController::class, 'standings']);
                Route::get('categories/{category}/adjustments', [StandingAdjustmentController::class, 'index']);
                Route::post('categories/{category}/adjustments', [StandingAdjustmentController::class, 'store']);
                Route::get('categories/{category}/leaderboard', [MatchController::class, 'leaderboard']);
                Route::get('categories/{category}/discipline', [MatchController::class, 'discipline']);
            });

            // ---- documents ----
            Route::middleware('event.perm.documents')->group(function () {
                Route::get('certificate-recipients', [CertificateController::class, 'recipients']);
                Route::post('certificates', [CertificateController::class, 'generate']);
                Route::get('id-card-recipients', [IdCardController::class, 'recipients']);
                Route::post('id-cards', [IdCardController::class, 'generate']);
                Route::post('id-cards/send', [IdCardController::class, 'send']);
                Route::get('bib-recipients', [BibController::class, 'recipients']);
                Route::post('bibs', [BibController::class, 'generate']);
                Route::post('bibs/send', [BibController::class, 'send']);
                Route::get('bibs/{entry}/download', [BibController::class, 'downloadEntry']);
                Route::get('personnel', [EventPersonnelController::class, 'index']);
                Route::put('personnel', [EventPersonnelController::class, 'sync']);
                Route::get('photos', [EventMediaController::class, 'photos']);
                Route::post('photos', [EventMediaController::class, 'storePhotos']);
                Route::get('sponsors', [EventMediaController::class, 'sponsors']);
                Route::post('sponsors', [EventMediaController::class, 'storeSponsor']);
            });
        });

        // Model-bound routes that carry no `{event}` segment — the category
        // middleware resolves the event itself from the bound parameter via
        // `EventOperatorAttribution::resolveEventFrom()`, passed as the two
        // extra alias arguments. See EnsureOperatorPermission.
        Route::middleware('event.perm.ticketing:ticketCategory,App\Models\TicketCategory')->group(function () {
            Route::patch('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'update']);
            Route::delete('ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'destroy']);
        });
        Route::middleware('event.perm.ticketing:ticketOrder,App\Models\TicketOrder')->group(function () {
            Route::post('ticket-orders/{ticketOrder}/check-in', [TicketSaleController::class, 'checkInOrder']);
        });
        Route::middleware('event.perm.registration:raceCategory,App\Models\RaceCategory')->group(function () {
            Route::patch('race-categories/{raceCategory}', [RaceCategoryController::class, 'update']);
            Route::delete('race-categories/{raceCategory}', [RaceCategoryController::class, 'destroy']);
        });
        Route::middleware('event.perm.registration:eventDiscountCode,App\Models\EventDiscountCode')->group(function () {
            Route::patch('event-discount-codes/{eventDiscountCode}', [EventDiscountCodeController::class, 'update']);
            Route::delete('event-discount-codes/{eventDiscountCode}', [EventDiscountCodeController::class, 'destroy']);
        });
        Route::middleware('event.perm.scheduling:adjustment,App\Models\StandingAdjustment')->group(function () {
            Route::delete('adjustments/{adjustment}', [StandingAdjustmentController::class, 'destroy']);
        });
        Route::middleware('event.perm.scheduling:match,App\Models\GameMatch')->group(function () {
            // `confirm` is deliberately absent here and stays `org.admin`-only
            // further up, untouched.
            Route::patch('matches/{match}', [MatchController::class, 'updateResult']);
            Route::patch('matches/{match}/schedule', [MatchController::class, 'updateSchedule']);
            Route::patch('matches/{match}/teams', [MatchController::class, 'updateTeams']);
            Route::patch('matches/{match}/slot', [MatchController::class, 'moveSlot']);
            Route::patch('matches/{match}/sides', [MatchController::class, 'swapSides']);
            Route::patch('matches/{match}/status', [MatchController::class, 'updateStatus']);
            Route::patch('matches/{match}/clock', [MatchController::class, 'updateClock']);
            Route::delete('matches/{match}', [MatchController::class, 'destroy']);
            Route::get('matches/{match}/stats', [MatchController::class, 'matchStats']);
            Route::put('matches/{match}/stats', [MatchController::class, 'saveMatchStats']);
            Route::get('matches/{match}/lineup-sheet', [LineupSheetController::class, 'download']);
            Route::get('matches/{match}/report', [MatchReportController::class, 'download']);
            Route::get('matches/{match}/rubbers', [RubberController::class, 'index']);
            Route::put('matches/{match}/rubbers', [RubberController::class, 'sync']);
        });
        Route::middleware('event.perm.scheduling:rubber,App\Models\MatchRubber')->group(function () {
            Route::patch('rubbers/{rubber}', [RubberController::class, 'update']);
        });
        Route::middleware('event.perm.documents:photo,App\Models\EventPhoto')->group(function () {
            Route::patch('photos/{photo}', [EventMediaController::class, 'updatePhoto']);
            Route::delete('photos/{photo}', [EventMediaController::class, 'destroyPhoto']);
        });
        Route::middleware('event.perm.documents:sponsor,App\Models\EventSponsor')->group(function () {
            Route::patch('sponsors/{sponsor}', [EventMediaController::class, 'updateSponsor']);
            Route::delete('sponsors/{sponsor}', [EventMediaController::class, 'destroySponsor']);
        });
        Route::middleware('event.perm.documents:certificate,App\Models\Certificate')->group(function () {
            Route::get('certificates/{certificate}/download', [CertificateController::class, 'download']);
            Route::post('certificates/{certificate}/send', [CertificateController::class, 'send']);
            Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy']);
        });
        Route::middleware('event.perm.documents:card,App\Models\IdCard')->group(function () {
            Route::get('id-cards/{card}/download', [IdCardController::class, 'downloadCard']);
        });

        // Certificate/ID-card/bib printing routes that carry no `{event}`
        // segment at all — they take an optional `event_id` (certificates
        // index, certificates/zip) or none (id-cards/issued, every batch
        // status/download route), because they predate this feature and
        // already took their shape from `tenant`, which only ever needed
        // `organization`. Neither `event.access` (requires `{event}` in the
        // path) nor the model-bound `event.perm.documents:*,…` above
        // (requires a bound Eloquent row to walk) fits them — a batch id is
        // a key into a Cache payload the queue job wrote, not a row.
        // `event.perm.documents.index`/`.batch` resolve the
        // `operator`/`organization` attributes these controllers already
        // read (`$request->attributes->get('operator')`, each controller's
        // own `ensureOwned()`/`ensureOwnedBatch()`) from `event_id` or the
        // batch's own cached payload — see their docblocks. Deliberately
        // inside this `tenant`-less group, same reasoning as the rest of it:
        // an operator must reach these without a `organization_members` row,
        // and each middleware falls back to plain org membership itself when
        // no event is identifiable (an org-wide certificate zip has none).
        Route::middleware('event.perm.documents.index')->group(function () {
            Route::get('certificates', [CertificateController::class, 'index']);
            Route::post('certificates/zip', [CertificateController::class, 'zip']);
            Route::get('id-cards/issued', [IdCardController::class, 'index']);
        });
        Route::middleware('event.perm.documents.batch:'.\App\Jobs\GenerateCertificateZipJob::class)->group(function () {
            Route::get('certificate-batches/{batch}', [CertificateController::class, 'zipStatus']);
            Route::get('certificate-batches/{batch}/download', [CertificateController::class, 'zipDownload']);
        });
        Route::middleware('event.perm.documents.batch:'.\App\Jobs\GenerateIdCardsJob::class)->group(function () {
            Route::get('id-card-batches/{batch}', [IdCardController::class, 'status']);
            Route::get('id-card-batches/{batch}/download', [IdCardController::class, 'download']);
        });
        Route::middleware('event.perm.documents.batch:'.\App\Jobs\GenerateBibsJob::class)->group(function () {
            Route::get('bib-batches/{batch}', [BibController::class, 'status']);
            Route::get('bib-batches/{batch}/download', [BibController::class, 'download']);
        });
        }); // end organizations/{organization} (event operator surfaces)

        // ---- SaaS Super Admin ----
        Route::middleware('superadmin')->prefix('admin')->group(function () {
            Route::apiResource('plans', PlanController::class);
            Route::put('plans/{plan}/features', [PlanFeatureController::class, 'sync']);
            Route::apiResource('feature-definitions', FeatureDefinitionController::class)
                ->parameters(['feature-definitions' => 'feature_definition'])
                ->except(['show']);

            // Cross-org event list, and the custom domain controls on it.
            // Domains are super-admin only: activating one spends Let's Encrypt
            // quota shared by the whole platform.
            Route::get('events', [AdminEventController::class, 'index']);
            Route::put('events/{event}/domain', [AdminEventController::class, 'updateDomain']);
            Route::post('events/{event}/domain/activate', [AdminEventController::class, 'activateDomain']);
            Route::delete('events/{event}/domain', [AdminEventController::class, 'destroyDomain']);
            // The undo for a mistake the state machine has no undo for:
            // `ongoing` cannot go back to `open`/`registration_closed` through
            // Event::TRANSITIONS, so an organizer who fat-fingers "Berlangsung"
            // has nowhere to click. Forces `events.status` past the state
            // machine and logs why — see EventController::forceStatus().
            Route::patch('events/{event}/force-status', [AdminEventController::class, 'forceStatus']);

            // How much of the platform is in use: tournaments by status.
            Route::get('stats', [AdminStatController::class, 'index']);

            // Settled money, cut by how people paid. Counted from the order
            // tables, never from the webhooks that settled them — see the
            // controller.
            Route::get('payment-stats', [AdminPaymentStatController::class, 'index']);

            // Public page traffic across the whole platform.
            Route::get('view-stats', [AdminViewStatController::class, 'index']);
            Route::get('view-stats/organizations', [AdminViewStatController::class, 'organizations']);
            Route::get('view-stats/events', [AdminViewStatController::class, 'events']);

            // Landing page content.
            // Plan checkout discount codes. No in-use guard on delete — orders
            // snapshot the code they redeemed, so nothing reads these back.
            Route::apiResource('discount-codes', DiscountCodeController::class)
                ->parameters(['discount-codes' => 'discount_code'])
                ->except(['show']);
            Route::apiResource('testimonials', TestimonialController::class)->except(['show']);
            Route::apiResource('faqs', FaqController::class)->except(['show']);

            // Blog. `show` is kept here, unlike the two above: the editor loads
            // one article by id, and the public route only serves published
            // ones — a draft could not be reopened through it.
            Route::apiResource('blog-categories', BlogCategoryController::class)
                ->parameters(['blog-categories' => 'blog_category'])
                ->except(['show']);
            Route::apiResource('blog-posts', BlogPostController::class)
                ->parameters(['blog-posts' => 'blog_post']);

            // Contact & socials: one row, so a settings pair instead of a resource.
            Route::get('site-settings', [SiteSettingController::class, 'index']);
            Route::put('site-settings', [SiteSettingController::class, 'update']);

            // Landing proof counters: a settings pair for the same reason — the
            // catalog in App\Support\LandingMetrics decides which metrics exist,
            // so only their label, order and visibility are editable.
            Route::get('landing-stats', [LandingStatController::class, 'index']);
            Route::put('landing-stats', [LandingStatController::class, 'update']);
            // The platform favicon. Guarded, unlike the uploads/* routes the
            // public registration form posts to — only this CMS uploads one.
            Route::post('uploads/favicon', [UploadController::class, 'favicon']);

            // Catalog administration.
            Route::get('engines', [ConfigOptionController::class, 'engines']);
            Route::put('sports/{sport}/stats', [SportController::class, 'syncStats']);
            Route::put('sports/{sport}/positions', [SportController::class, 'syncPositions']);
            Route::put('sports/{sport}/official-roles', [SportController::class, 'syncOfficialRoles']);
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
            // The fourth rail. Kept beside the organizer's own refund queue
            // rather than replaced by it: that one rules on a request a runner
            // filed, this is the hatch for one nobody asked for.
            Route::post('race-entries/{raceEntry}/refund', [AdminRefundController::class, 'refundRaceEntry']);

            // What a refund leaves behind: the buyer's money is in the
            // platform's own Midtrans account, so every granted refund owes a
            // manual transfer out to the organizer. Before this queue nothing
            // notified anybody and race entries were not even listed on
            // /admin/payments, so the debt was invisible even to somebody
            // looking for it.
            Route::get('refund-payouts', [AdminRefundPayoutController::class, 'index']);
            Route::patch('refund-payouts/{refundPayout}/complete', [AdminRefundPayoutController::class, 'complete']);

            // Manual plan payments waiting on us. Unlike the organizer's own
            // verification queue, this money lands in the platform's account —
            // so only a super admin may rule on the receipt.
            Route::get('plan-orders', [AdminPlanOrderController::class, 'index']);
            // Paid, unspent, and old. Not a queue — a ledger of what the
            // platform owes in entitlements nobody has claimed.
            Route::get('plan-orders/idle', [AdminPlanOrderController::class, 'idle']);
            // Receipts already accepted. Same column the queue filters on, so a
            // row leaves that list exactly as it lands in this one.
            Route::get('plan-orders/history', [AdminPlanOrderController::class, 'history']);
            // Every settled purchase, both rails. The three lists above all key
            // off manual-transfer columns, so gateway purchases show up only here.
            Route::get('plan-orders/purchases', [AdminPlanOrderController::class, 'purchases']);
            Route::post('plan-orders/{planOrder}/approve', [AdminPlanOrderController::class, 'approve']);
            Route::post('plan-orders/{planOrder}/reject', [AdminPlanOrderController::class, 'reject']);

            // Domains an organizer has paid for and we still have to register.
            // Only the `register` kind ever lands here: `own` and `subdomain`
            // are fulfilled by being paid for (see
            // EventPlanOrderService::applyDomainAddon), so a queue listing them
            // would be a queue of work nobody has to do.
            Route::get('domain-orders', [AdminDomainOrderController::class, 'index']);
            Route::post('domain-orders/{planOrder}/fulfil', [AdminDomainOrderController::class, 'fulfil']);
            // The escape hatch for an event stuck on the wrong plan — see the
            // controller for why it is super_admin and not the organizer's own.
            Route::post('events/{event}/reassign-plan', [AdminPlanOrderController::class, 'reassignPlan']);
            // The picker behind that button: which event should this spare
            // credit be applied to?
            Route::get('organizations/{organization}/events', [AdminPlanOrderController::class, 'organizationEvents']);

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
            // Support reset: set a user's password without knowing the old one.
            // Ordinary users only, same as impersonate.
            Route::post('users/{user}/password', [AdminUserController::class, 'resetPassword']);
        });
    });

    // ---- Webhooks (signature-verified inside controller) ----
    Route::post('webhooks/midtrans', [MidtransWebhookController::class, 'handle']);
});
