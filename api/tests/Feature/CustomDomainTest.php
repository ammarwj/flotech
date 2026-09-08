<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Custom domains per event.
 *
 * Most of these compare two things that must come out different, because the
 * failure modes here are all "the filter never ran": a domain list that serves
 * uncertified hosts, a CORS middleware that waves through every origin, an nginx
 * config that emits a 443 block pointing at a certificate that does not exist.
 * Asserting one half of each pair passes just as happily against all of those.
 */
class CustomDomainTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** An event whose domain is assigned, certified and live. */
    private function certified(Event $event, string $domain): Event
    {
        $event->forceFill([
            'custom_domain' => $domain,
            'domain_verified_at' => now(),
            'domain_certified_at' => now(),
        ])->save();

        DomainService::flush();

        return $event;
    }

    // ---- assignment ---------------------------------------------------------

    public function test_super_admin_assigns_and_normalizes_a_domain(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        // All three shapes are the same name written by someone who copied it
        // out of a browser bar. Comparing the stored value against the bare
        // hostname is what proves normalize() ran at all.
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/events/{$event->id}/domain", [
                'custom_domain' => 'HTTPS://Event-A.test/daftar',
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_domain', 'event-a.test')
            // Assigned is not activated: no certificate has been issued yet.
            ->assertJsonPath('data.domain_status', 'pending');

        $this->assertSame('event-a.test', $event->refresh()->custom_domain);
    }

    public function test_domain_cannot_be_taken_by_two_events(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $first = $this->eventOn($org);
        $second = $this->eventOn($org);

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/events/{$first->id}/domain", ['custom_domain' => 'event-a.test'])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/events/{$second->id}/domain", ['custom_domain' => 'event-a.test'])
            ->assertStatus(422);

        // Compare the survivor, not just the status: a check that runs after the
        // write would still return 422 having already moved the domain.
        $this->assertSame('event-a.test', $first->refresh()->custom_domain);
        $this->assertNull($second->refresh()->custom_domain);
    }

    public function test_draft_event_cannot_take_a_domain(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $draft = $this->eventOn($org, attrs: ['status' => 'draft']);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/events/{$draft->id}/domain", ['custom_domain' => 'draft.test'])
            ->assertStatus(422);

        $this->assertNull($draft->refresh()->custom_domain);
    }

    public function test_platform_own_hostnames_are_reserved(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/events/{$event->id}/domain", [
                'custom_domain' => config('domains.reserved')[0],
            ])
            ->assertStatus(422);

        $this->assertNull($event->refresh()->custom_domain);
    }

    public function test_malformed_domain_is_rejected_not_repaired(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/events/{$event->id}/domain", ['custom_domain' => 'bukan domain'])
            ->assertStatus(422);

        $this->assertNull($event->refresh()->custom_domain);
    }

    public function test_regular_user_cannot_touch_domains(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $this->actingAs(User::factory()->create(['role' => 'user']), 'api')
            ->putJson("/api/v1/admin/events/{$event->id}/domain", ['custom_domain' => 'event-a.test'])
            ->assertStatus(403);
    }

    // ---- activation ---------------------------------------------------------

    /**
     * The rate-limit guard. Let's Encrypt allows 5 failures per hostname per
     * hour counted per *account*, so one domain with bad DNS can lock out every
     * other domain we hold. Asserting the 422 alone would pass even if certbot
     * had been run first and burnt the quota — the assertion that matters is
     * that no process was started.
     */
    public function test_activation_never_calls_certbot_when_dns_does_not_point_here(): void
    {
        Process::fake();

        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);
        // Empty server_ip (the config default in tests) makes verifyDns refuse.
        $event->forceFill(['custom_domain' => 'event-a.test'])->save();

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/events/{$event->id}/domain/activate")
            ->assertStatus(422);

        Process::assertNothingRan();

        $event->refresh();
        $this->assertNull($event->domain_certified_at);
        $this->assertNotNull($event->domain_error);
        // Stamped even on failure: this is what domains:sync backs off on.
        $this->assertNotNull($event->domain_attempted_at);
    }

    public function test_successful_activation_certifies_the_domain(): void
    {
        Process::fake();

        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);
        $event->forceFill(['custom_domain' => 'event-a.test'])->save();

        $this->mock(DomainService::class, function ($mock) {
            $mock->makePartial()->shouldReceive('verifyDns')->andReturn(true);
        });

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/events/{$event->id}/domain/activate")
            ->assertOk()
            ->assertJsonPath('data.domain_status', 'active');

        // The command is built in array form (see certbotCommand), so it has to
        // be joined before matching.
        Process::assertRan(fn ($process) => in_array('certonly', (array) $process->command, true));

        $event->refresh();
        $this->assertNotNull($event->domain_certified_at);
        $this->assertNull($event->domain_error);
    }

    /**
     * Certbot always ends its output with a fixed "ask for help at
     * community.letsencrypt.org" footer — the least useful line in the run,
     * and what a naive "last line of output" would surface to the admin
     * instead of the actual DNS/challenge reason a few lines above it.
     * Comparing against that footer text is what proves the real line won.
     */
    public function test_failed_activation_surfaces_the_certbot_detail_line_not_the_footer(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: implode("\n", [
                    'Saving debug log to /tmp/certbot-log-xxx/log',
                    'Certbot failed to authenticate some domains (authenticator: webroot).',
                    '  Domain: event-a.test',
                    '  Type:   unauthorized',
                    '  Detail: 198.51.100.1: Invalid response from http://event-a.test/.well-known/acme-challenge/x: 404',
                    '',
                    'Some challenges have failed.',
                    'Ask for help or search for solutions at https://community.letsencrypt.org.'
                        .' See the logfile /tmp/certbot-log-xxx/log or re-run Certbot with -v for more details.',
                ]),
                exitCode: 1,
            ),
        ]);

        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);
        $event->forceFill(['custom_domain' => 'event-a.test'])->save();

        $this->mock(DomainService::class, function ($mock) {
            $mock->makePartial()->shouldReceive('verifyDns')->andReturn(true);
        });

        $response = $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/events/{$event->id}/domain/activate")
            ->assertStatus(422);

        $message = $response->json('message');
        $this->assertStringContainsString('Detail:', $message);
        $this->assertStringNotContainsString('Ask for help', $message);
    }

    public function test_releasing_clears_every_derived_column(): void
    {
        Process::fake();

        $org = $this->orgFor(User::factory()->create());
        $event = $this->certified($this->eventOn($org), 'event-a.test');

        $this->actingAs($this->superAdmin(), 'api')
            ->deleteJson("/api/v1/admin/events/{$event->id}/domain")
            ->assertOk()
            ->assertJsonPath('data.domain_status', 'none');

        $event->refresh();
        $this->assertNull($event->custom_domain);
        $this->assertNull($event->domain_certified_at);
        $this->assertNull($event->domain_verified_at);
    }

    // ---- the routing table --------------------------------------------------

    /**
     * Two events of one organizer: one certified, one merely assigned. Only the
     * certified one may be served — the other has no certificate, so routing
     * traffic to it hands visitors a TLS error. Asserting the certified one
     * appears would pass against a list that returns every assigned domain.
     */
    public function test_public_domain_list_carries_only_certified_domains(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $live = $this->certified($this->eventOn($org), 'live.test');
        $pending = $this->eventOn($org);
        $pending->forceFill(['custom_domain' => 'pending.test'])->save();

        DomainService::flush();

        // Read the map out rather than using a dotted JSON path: the keys are
        // hostnames, and data_get would split them on their own dots.
        $domains = $this->getJson('/api/v1/public/domains')->assertOk()->json('data.domains');

        $this->assertSame(['live.test'], array_keys($domains));
        $this->assertSame($live->slug, $domains['live.test']['event_slug']);
        $this->assertSame($org->slug, $domains['live.test']['org_slug']);
    }

    // ---- CORS ---------------------------------------------------------------

    /**
     * A page served from a custom domain calls api.floevent.id, so without a
     * dynamic allowlist every fetch it makes is blocked by the browser. The pair
     * is the point: asserting only that the live domain is allowed stays green
     * against a middleware that echoes back whatever Origin it is handed.
     */
    public function test_cors_allows_a_live_domain_but_not_an_arbitrary_one(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $this->certified($this->eventOn($org), 'live.test');

        $this->getJson('/api/v1/public/domains', ['Origin' => 'https://live.test'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://live.test');

        $this->getJson('/api/v1/public/domains', ['Origin' => 'https://penipu.test'])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    // ---- generated nginx config ---------------------------------------------

    /**
     * The ACME chicken-and-egg, stated as a comparison. A domain waiting for its
     * certificate still needs a port-80 block — that is what answers the
     * challenge that produces the certificate — but it must NOT get a 443 block,
     * because that block names certificate files which do not exist yet and
     * `nginx -t` would reject the whole file, taking every other vhost on this
     * host down with it.
     */
    public function test_nginx_config_emits_443_only_once_a_certificate_exists(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $this->certified($this->eventOn($org), 'live.test');
        $pending = $this->eventOn($org);
        $pending->forceFill(['custom_domain' => 'pending.test'])->save();

        $config = app(DomainService::class)->renderNginxConfig();

        $this->assertStringContainsString('server_name live.test;', $config);
        $this->assertStringContainsString('server_name pending.test;', $config);

        // One 443 block, and it belongs to the certified domain.
        $this->assertSame(1, substr_count($config, 'listen 443 ssl;'));
        $this->assertStringContainsString('live/live.test/fullchain.pem', $config);
        $this->assertStringNotContainsString('pending.test/fullchain.pem', $config);

        // Neither may ever appear: this host also fronts other stacks, only one
        // default_server is allowed per port, and a regex server_name is matched
        // before the default — either would silently steal a neighbour's traffic.
        $this->assertStringNotContainsString('default_server', $config);
        $this->assertStringNotContainsString('~^', $config);
    }

    // ---- the admin list -----------------------------------------------------

    public function test_admin_event_list_spans_organizations_and_is_searchable(): void
    {
        $a = $this->orgFor(User::factory()->create(), 'Alpha');
        $b = $this->orgFor(User::factory()->create(), 'Beta');

        $this->eventOn($a, attrs: ['name' => 'Liga Mahasiswa']);
        $this->eventOn($b, attrs: ['name' => 'Turnamen Futsal']);

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/events')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        // Lowercase needle against a capitalised name: a bare LIKE is
        // case-sensitive on Postgres and would find nothing in production while
        // passing here on sqlite. See App\Support\Search.
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/events?q=liga')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Liga Mahasiswa');
    }
}
