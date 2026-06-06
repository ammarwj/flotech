<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_tokens(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Andi Saputra',
            'email' => 'andi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email']]]);

        $this->assertDatabaseHas('users', ['email' => 'andi@example.com', 'role' => 'user']);
        $this->assertNotNull($response->getCookie('refresh_token', false));
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.token_type', 'bearer');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'login2@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login2@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_refresh_rotates_token_via_cookie(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Refresh User',
            'email' => 'refresh@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $refreshToken = $register->getCookie('refresh_token', false)->getValue();

        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']]);

        // Old refresh token was rotated (revoked) and can't be reused.
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }
}
