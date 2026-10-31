<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::factory()->create();
    }

    protected function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'Org Tenant',
            'slug' => 'org-tenant',
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * The id goes into a `where id = ?` on a uuid column, so a non-uuid segment
     * has to be refused before it ever reaches the database: Postgres raises
     * 22P02 on `where id = 'null'` instead of matching nothing, and that came
     * back to the browser as a 500 naming the table, the host and the
     * connection. The literal `null` is what a template string over a null org
     * id spells, and that is the bug that actually happened — a client reading
     * an org that had not resolved yet.
     *
     * **Asserting the 404 proves nothing here, and the counting is the test.**
     * These suites run on sqlite, which matches nothing for `id = 'null'` and
     * hands back the same 404 the fix does — so the status is identical on a
     * build with no guard at all, and only production would have told us. What
     * *is* visible on either driver is whether the query ran: a malformed id
     * must never reach `organizations`, while a well-formed uuid that happens
     * not to exist must still be looked up. Compared in one test, because
     * either half alone also passes on a build that refuses every id.
     */
    public function test_a_malformed_organization_id_never_reaches_the_database(): void
    {
        $user = $this->owner();

        foreach (['null', 'undefined', 'not-a-uuid'] as $garbage) {
            $this->assertSame(
                0,
                $this->orgQueriesDuring(fn () => $this->actingAs($user, 'api')
                    ->getJson("/api/v1/organizations/{$garbage}/plan-orders")
                    ->assertStatus(404)),
                "'{$garbage}' was sent to the database as a uuid",
            );
        }

        $this->assertSame(
            1,
            $this->orgQueriesDuring(fn () => $this->actingAs($user, 'api')
                ->getJson('/api/v1/organizations/'.fake()->uuid().'/plan-orders')
                ->assertStatus(404)),
        );
    }

    /** How many queries the given call made against `organizations`. */
    protected function orgQueriesDuring(callable $fn): int
    {
        $hits = 0;
        DB::listen(function ($query) use (&$hits) {
            if (str_contains($query->sql, 'from "organizations"')) {
                $hits++;
            }
        });

        $fn();

        // No unlisten in Laravel: the next call would otherwise keep counting
        // through this closure as well.
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $hits;
    }

    /**
     * `X-Organization-Id` is the middleware's other source, read whenever a
     * route carries no `{organization}` segment. Every `tenant` route has one
     * today, so there is nothing to drive this through HTTP — the guard covers
     * it anyway because the check sits on the resolved `$id`, after the two
     * sources have merged, rather than on the segment. Keeping one reader is
     * the point: a check spelled per source is a second rule to keep in step.
     */
    /** A real org still resolves — the guard must not reject valid uuids. */
    public function test_a_real_organization_still_resolves(): void
    {
        $owner = $this->owner();
        $org = $this->org($owner);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders")
            ->assertStatus(200);
    }
}
