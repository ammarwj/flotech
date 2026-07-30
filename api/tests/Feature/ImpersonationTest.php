<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * "Login as" — a super admin acting as an ordinary user for support.
 *
 * The feature hands out a credential for somebody else's account, so what is
 * asserted here is mostly what it must NOT do: no refresh cookie, no reach into
 * another super admin, and no damage to the admin's own session — that last one
 * is what the whole frontend recovery leans on, since an impersonation token has
 * nothing of its own to renew with.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function url(User $target): string
    {
        return "/api/v1/admin/users/{$target->id}/impersonate";
    }

    /**
     * Claims of a JWT, read straight off the wire.
     *
     * @return array<string, mixed>
     */
    private function claims(string $token): array
    {
        $body = explode('.', $token)[1];

        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    }

    public function test_super_admin_can_impersonate_an_ordinary_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin, 'api')
            ->postJson($this->url($target))
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email']]]);
    }

    /**
     * Asserted by **comparison** against a real login, in one test: both mint an
     * access token, and only one may set a refresh cookie. Asserting that the
     * token comes back would pass even if the cookie came with it — and that
     * cookie is a 30-day session in someone else's name, renewable by whoever
     * holds it, which is exactly what this feature refuses to create.
     */
    public function test_impersonating_issues_no_refresh_cookie_unlike_a_real_login(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user', 'password' => bcrypt('password123')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'password123',
        ])->assertOk();

        $impersonation = $this->actingAs($admin, 'api')
            ->postJson($this->url($target))
            ->assertOk();

        $this->assertNotNull(
            $login->getCookie(AuthController::REFRESH_COOKIE, false),
            'a real login must set the refresh cookie — otherwise this test proves nothing',
        );
        $this->assertNull(
            $impersonation->getCookie(AuthController::REFRESH_COOKIE, false),
            'impersonation must not hand out a renewable session for another account',
        );
        $this->assertDatabaseCount('user_refresh_tokens', 1);
    }

    /**
     * The invariant the frontend recovery is built on: the admin's own refresh
     * token survives impersonating, so a dead impersonation token can be
     * replaced without anyone logging in again.
     */
    public function test_the_admins_own_session_survives_impersonating(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);

        // Driven through AuthService rather than the login endpoint so the plain
        // refresh token is in hand: the browser only ever sees it as an HttpOnly
        // cookie, and round-tripping that through the test harness would be
        // testing Laravel's cookie encryption instead of this feature.
        $auth = app(AuthService::class);
        $plain = $auth->issueTokens($admin, Request::create('/'))['refresh_token'];

        $this->actingAs($admin, 'api')->postJson($this->url($target))->assertOk();

        $rotated = $auth->rotate($plain, Request::create('/'));

        $this->assertNotNull($rotated, 'the admin refresh token must still be exchangeable');
        $this->assertSame(
            $admin->id,
            $this->claims($rotated['access_token'])['sub'],
            'and it must still hand back the admin, not the impersonated user',
        );
    }

    public function test_the_token_acts_as_the_target_and_records_the_admin(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);

        $token = $this->actingAs($admin, 'api')
            ->postJson($this->url($target))
            ->assertOk()
            ->json('data.access_token');

        // Decoded off the wire rather than through the JWTAuth facade: actingAs()
        // leaves the facade bound to the admin's own token, and getPayload() then
        // answers about that one instead of the token under test.
        $payload = $this->claims($token);
        $this->assertSame($target->id, $payload['sub'], 'the token acts as the target');
        $this->assertSame($admin->id, $payload['act_as'], 'and records who opened it');
    }

    /**
     * That the token actually authenticates as the target, over HTTP.
     *
     * Minted through the service rather than the endpoint on purpose: reaching
     * the endpoint needs actingAs(), which leaves the api guard bound to the
     * admin for every later request in the test — an Authorization header sent
     * afterwards is ignored, and this assertion would silently be checking the
     * admin's session instead of the token under test.
     */
    public function test_the_token_authenticates_as_the_target_over_http(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);

        $token = app(AuthService::class)->issueImpersonationToken($target, $admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $target->id);
    }

    public function test_a_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $admin = $this->admin();
        $peer = $this->admin();

        $this->actingAs($admin, 'api')
            ->postJson($this->url($peer))
            ->assertStatus(403);
    }

    public function test_a_super_admin_cannot_impersonate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'api')
            ->postJson($this->url($admin))
            ->assertStatus(422);
    }

    public function test_an_ordinary_user_cannot_impersonate_anyone(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'api')
            ->postJson($this->url($target))
            ->assertStatus(403);
    }
}
