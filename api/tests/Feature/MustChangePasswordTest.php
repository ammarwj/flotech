<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The forced rotation behind the officiating invite: an account holding a
 * password somebody else chose for it is refused everywhere that matters until
 * it picks its own.
 *
 * The gated route here is declared in the test rather than borrowed from
 * routes/api.php, because the first real one arrives with the officiating
 * surface in the next phase. What is under test is the middleware's decision,
 * not any particular URL — and declaring it makes the "same request, two users"
 * comparison exact, with nothing else in the stack able to account for the
 * difference.
 */
class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:api', 'password.rotated'])
            ->get('/api/v1/testing/rotated', fn () => response()->json(['ok' => true]));
    }

    public function test_a_flagged_account_is_refused_where_an_ordinary_one_is_admitted(): void
    {
        $ordinary = User::factory()->create();
        $flagged = User::factory()->mustChangePassword()->create();

        // Same URL, same method, two users. Asserting only the 403 would pass
        // just as well if the middleware refused everyone.
        $this->actingAs($ordinary, 'api')
            ->getJson('/api/v1/testing/rotated')
            ->assertOk();

        $this->actingAs($flagged, 'api')
            ->getJson('/api/v1/testing/rotated')
            ->assertStatus(403)
            // The web shell branches on this code to know which screen to show;
            // a bare 403 is indistinguishable from "not your event".
            ->assertJsonPath('errors.code.0', 'must_change_password');
    }

    public function test_changing_the_password_lifts_the_refusal(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => 'welcomefloevent1',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/testing/rotated')
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'welcomefloevent1',
                'password' => 'rahasiabaru9',
                'password_confirmation' => 'rahasiabaru9',
            ])
            ->assertOk();

        // Before and after, so the test cannot pass on a middleware that never
        // refused anything in the first place.
        $this->actingAs($user->fresh(), 'api')
            ->getJson('/api/v1/testing/rotated')
            ->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_the_mailed_default_cannot_be_kept_as_the_new_password(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => 'welcomefloevent1',
        ]);
        $before = $user->fresh()->password;

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'welcomefloevent1',
                'password' => 'welcomefloevent1',
                'password_confirmation' => 'welcomefloevent1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // The stored hash, not just the status: a 422 raised after the write
        // would look identical from the outside. This is what makes the
        // rotation real rather than ceremonial — without `different`, the
        // invitee can "rotate" straight back to the password in their inbox.
        $this->assertSame($before, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('welcomefloevent1', $user->fresh()->password));
    }

    public function test_the_account_endpoints_stay_reachable_while_flagged(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        // The pair is the allowlist: the duty surface is shut, but the endpoints
        // the web shell needs to explain why — and to fix it — are not. Shut
        // both and the user sees a spinner with no way out.
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/testing/rotated')
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_the_flag_is_published_by_every_account_that_has_it_or_not(): void
    {
        $ordinary = User::factory()->create();
        $flagged = User::factory()->mustChangePassword()->create();

        // Published always, never conditionally: a key that disappears when
        // false reads as `undefined` in the shell's gate, which is falsy and so
        // looks correct — right up until the key also goes missing for someone
        // who *should* be gated.
        $this->actingAs($ordinary, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->actingAs($flagged, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_the_flag_cannot_be_set_by_mass_assignment(): void
    {
        $user = User::factory()->create();

        $user->fill(['must_change_password' => true])->save();

        // A lock that `fill()` can close is a lock anyone with a user-update
        // path can close on somebody else.
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
