<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NewTeamRegistered;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SubscriptionActivated;
use App\Notifications\TeamRegistrationSubmitted;
use App\Notifications\TeamStatusChanged;
use App\Notifications\WithdrawalCompleted;
use App\Notifications\WithdrawalRejected;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The mails the app owes people. Two rules run through all of them:
 *
 * - a team carries a phone number, not an email, so a team entered offline (no
 *   manager account) is mailable to nobody — and must stay silent rather than
 *   fall back to some other address;
 * - money settles on a re-delivered Midtrans webhook, so a second copy of a
 *   receipt must never reach the inbox for one payment.
 */
class MailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price_monthly' => 50000, 'price_yearly' => 500000]);
        $plan->features()->create(['feature_key' => 'max_teams_per_event', 'value' => '10']);

        return Organization::create([
            'name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function openEvent(Organization $org)
    {
        return $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal', 'tournament_format' => 'league',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);
    }

    public function test_registering_a_team_mails_the_manager_and_the_organizer(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->openEvent($org);
        $manager = User::factory()->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
            ])
            ->assertCreated();

        Notification::assertSentTo($manager, TeamRegistrationSubmitted::class);
        Notification::assertSentTo($owner, NewTeamRegistered::class);
    }

    public function test_a_team_entered_offline_has_nobody_to_mail(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->openEvent($org);

        // Offline entry: the organizer types the team in, so it has no manager
        // account — and teams hold a phone number, not an email address.
        $teamId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", [
                'name' => 'Tim Kampung',
                'contact_name' => 'Joko',
                'contact_phone' => '08120000000',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'status' => 'rejected',
            ])
            ->assertOk();

        Notification::assertNothingSentTo($owner);
        Notification::assertNotSentTo(User::all(), TeamStatusChanged::class);
    }

    public function test_the_organizers_verdict_reaches_the_manager_but_only_once(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->openEvent($org);
        $manager = User::factory()->create();

        $team = $event->teams()->create([
            'name' => 'Garuda FC', 'contact_name' => 'Andi', 'contact_phone' => '08123456789',
            'status' => 'pending', 'manager_user_id' => $manager->id, 'registered_at' => Carbon::now(),
        ]);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team->id}";

        $this->actingAs($owner, 'api')->patchJson($url, ['status' => 'approved'])->assertOk();

        Notification::assertSentTo(
            $manager,
            TeamStatusChanged::class,
            fn (TeamStatusChanged $n) => $n->status === 'approved',
        );

        // Re-saving the same verdict (to assign a group, say) is not a new verdict.
        $this->actingAs($owner, 'api')
            ->patchJson($url, ['status' => 'approved', 'group_name' => 'A'])
            ->assertOk();

        Notification::assertSentToTimes($manager, TeamStatusChanged::class, 1);
    }

    public function test_a_redelivered_webhook_does_not_send_a_second_receipt(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $plan = $org->plan;

        $service = app(SubscriptionService::class);
        $subscription = $service->checkout($org, $plan, 'monthly')['subscription'];

        // Midtrans re-delivers; activate() runs twice for one payment.
        $service->activate($subscription->fresh());
        $service->activate($subscription->fresh());

        Notification::assertSentToTimes($owner, SubscriptionActivated::class, 1);
    }

    public function test_the_organizer_hears_what_happened_to_their_payout(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $owner = User::factory()->create();
        $org = $this->org($owner);
        Wallet::create(['organization_id' => $org->id, 'balance_available' => 400000, 'total_earned' => 400000]);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso', 'is_primary' => true,
        ]);

        // Only one payout may be open at a time, so these run in sequence.
        $done = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$done}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
                'transfer_reference' => 'TRX-9911',
            ])
            ->assertOk();

        $refused = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$refused}/reject", [
                'admin_note' => 'Nama rekening tidak cocok dengan organisasi.',
            ])
            ->assertOk();

        Notification::assertSentTo($owner, WithdrawalCompleted::class);
        Notification::assertSentTo(
            $owner,
            WithdrawalRejected::class,
            fn (WithdrawalRejected $n) => str_contains($n->reason, 'Nama rekening'),
        );
    }

    public function test_password_reset_uses_our_own_indonesian_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'lupa@test.id']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lupa@test.id'])->assertOk();

        // Not Illuminate\Auth\Notifications\ResetPassword, which speaks English.
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }
}
