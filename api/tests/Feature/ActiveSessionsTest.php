<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin "Sesi Aktif": who is logged in (has an active device session) and who is
 * currently accessing (last_seen_at, stamped by the TrackLastSeen middleware).
 */
class ActiveSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function makeSession(User $user, array $attrs = []): UserRefreshToken
    {
        return UserRefreshToken::create(array_merge([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', Str::random(64)),
            'device_info' => 'Mozilla/5.0 (TestDevice)',
            'ip_address' => '10.0.0.1',
            'expires_at' => now()->addDays(30),
        ], $attrs));
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']), 'api')
            ->getJson('/api/v1/admin/active-sessions')
            ->assertStatus(403);
    }

    public function test_lists_only_recently_active_users(): void
    {
        // Currently accessing: recent last_seen + an open session.
        $online = User::factory()->create(['full_name' => 'Online User', 'last_seen_at' => now()]);
        $this->makeSession($online);

        // Logged in but idle for hours — a valid 30-day token, but not "accessing".
        // Must NOT drown the list.
        $idle = User::factory()->create(['full_name' => 'Idle User', 'last_seen_at' => now()->subHours(2)]);
        $this->makeSession($idle);

        // Never made an authenticated request (last_seen null) → not listed.
        $unseen = User::factory()->create(['full_name' => 'Unseen User', 'last_seen_at' => null]);
        $this->makeSession($unseen);

        $data = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/active-sessions')
            ->assertOk()
            ->json('data');

        $emails = collect($data)->pluck('email');
        $this->assertTrue($emails->contains($online->email));
        $this->assertFalse($emails->contains($idle->email));
        $this->assertFalse($emails->contains($unseen->email));

        $row = collect($data)->firstWhere('email', $online->email);
        $this->assertTrue($row['online']);
        $this->assertSame(1, $row['session_count']);
        $this->assertSame('10.0.0.1', $row['sessions'][0]['ip_address']);
    }

    public function test_revoked_or_expired_session_leaves_no_device_rows(): void
    {
        // A user active recently but whose only session was revoked/expired still
        // shows (they were accessing), but with no active device rows.
        $user = User::factory()->create(['last_seen_at' => now()]);
        $this->makeSession($user, ['revoked_at' => now()]);
        $this->makeSession($user, ['expires_at' => now()->subDay()]);

        $data = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/active-sessions')
            ->assertOk()
            ->json('data');

        $row = collect($data)->firstWhere('email', $user->email);
        $this->assertNotNull($row);
        $this->assertSame(0, $row['session_count']);
    }

    public function test_online_flag_reflects_last_seen_recency(): void
    {
        $fresh = User::factory()->create(['last_seen_at' => now()->subMinute()]);
        $this->makeSession($fresh);

        $idle = User::factory()->create(['last_seen_at' => now()->subMinutes(10)]);
        $this->makeSession($idle);

        $data = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/active-sessions')
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($data)->firstWhere('email', $fresh->email)['online']);
        $this->assertFalse(collect($data)->firstWhere('email', $idle->email)['online']);
    }

    public function test_middleware_stamps_last_seen_on_authenticated_request(): void
    {
        $user = User::factory()->create(['last_seen_at' => null]);
        $this->assertNull($user->fresh()->last_seen_at);

        // Any authenticated endpoint runs the track.seen middleware.
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/organizations')
            ->assertOk();

        $this->assertNotNull($user->fresh()->last_seen_at);
    }
}
