<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two doors change a password: the owner's own (needs the current password) and
 * a super admin's reset (deliberately doesn't). Both must revoke sessions —
 * otherwise a password change means nothing to whoever already holds a refresh
 * token, which is exactly the case the reset door exists for.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    /** Log in and hand back the plaintext refresh token that session got. */
    private function loginRefreshToken(string $email, string $password = 'password123'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
            ->getCookie('refresh_token', false)
            ->getValue();
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/password', [
            'current_password' => 'password123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(401);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'not-my-password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Password saat ini salah.');

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_change_password_rejects_reusing_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_change_password_signs_out_other_devices_but_not_this_one(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => 'password123']);

        $otherDevice = $this->loginRefreshToken('owner@example.com');
        $thisDevice = $this->loginRefreshToken('owner@example.com');

        $this->actingAs($user, 'api')
            ->withCredentials()
            ->withUnencryptedCookie('refresh_token', $thisDevice)
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 1);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));

        // The tab that made the change keeps working; the other one is dead.
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $thisDevice)
            ->postJson('/api/v1/auth/refresh')->assertOk();
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $otherDevice)
            ->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }

    public function test_admin_reset_sets_password_and_kills_every_session(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $user = User::factory()->create(['email' => 'target@example.com', 'password' => 'password123']);

        $deviceA = $this->loginRefreshToken('target@example.com');
        $deviceB = $this->loginRefreshToken('target@example.com');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$user->id}/password", [
                'password' => 'resetbyadmin1',
                'password_confirmation' => 'resetbyadmin1',
            ])
            ->assertOk();

        // Unlike the self-service door, nothing is spared here.
        $this->assertSame(0, UserRefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        foreach ([$deviceA, $deviceB] as $token) {
            $this->withCredentials()->withUnencryptedCookie('refresh_token', $token)
                ->postJson('/api/v1/auth/refresh')->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'resetbyadmin1',
        ])->assertOk();
    }

    public function test_admin_reset_refuses_another_super_admin_and_self(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $peer = User::factory()->create(['role' => 'super_admin', 'password' => 'password123']);

        $payload = ['password' => 'takeover12345', 'password_confirmation' => 'takeover12345'];

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$peer->id}/password", $payload)
            ->assertStatus(403);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$admin->id}/password", $payload)
            ->assertStatus(422);

        $this->assertTrue(Hash::check('password123', $peer->fresh()->password));
    }

    public function test_admin_reset_is_closed_to_ordinary_users(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/admin/users/{$victim->id}/password", [
                'password' => 'hijacked1234',
                'password_confirmation' => 'hijacked1234',
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check('password123', $victim->fresh()->password));
    }
}
