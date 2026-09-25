<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\TicketPosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The printable QR poster for an event's ticket shop.
 *
 * Asserted on the HTML the service renders, not the PDF bytes: dompdf output is
 * compressed streams, so a test holding those can only say that *something* was
 * produced — and that stays green when the QR stops carrying the right URL,
 * which is the only thing this sheet exists to do.
 */
class TicketPosterTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function orgWithOwner(): Organization
    {
        return $this->orgFor(User::factory()->create());
    }

    private function ticketedEvent(Organization $org, array $attrs = []): Event
    {
        return $this->eventOn($org, null, [
            'name' => 'MBU Cup',
            'slug' => 'mbu-cup',
            'status' => 'open',
            ...$attrs,
        ]);
    }

    public function test_poster_encodes_the_public_purchase_url(): void
    {
        config(['app.frontend_url' => 'https://floevent.id']);

        $org = $this->orgWithOwner();
        $event = $this->ticketedEvent($org);

        $posters = app(TicketPosterService::class);

        $this->assertSame(
            "https://floevent.id/{$org->slug}/mbu-cup/tickets",
            $posters->purchaseUrl($event),
        );

        // The URL is also spelled out on the sheet: a poster whose only copy of
        // the address is inside the QR has no second route for anyone whose
        // camera will not read it.
        $this->assertStringContainsString(
            "https://floevent.id/{$org->slug}/mbu-cup/tickets",
            $posters->html($event),
        );
    }

    /**
     * Compare a plain event with one on its own hostname: the poster keeps the
     * platform URL either way. Print outlives configuration — a domain can be
     * retired, and `proxy.ts` already forwards the platform path onto a live
     * domain, never the reverse. Asserting only the first event would stay
     * green even if the poster started printing a hostname that can lapse.
     */
    public function test_custom_domain_does_not_change_the_printed_url(): void
    {
        config(['app.frontend_url' => 'https://floevent.id']);

        $org = $this->orgWithOwner();
        $plain = $this->ticketedEvent($org, ['slug' => 'plain']);
        $owned = $this->ticketedEvent($org, [
            'slug' => 'owned',
            'custom_domain' => 'mbucup.id',
            'domain_certified_at' => now(),
        ]);

        $posters = app(TicketPosterService::class);

        $this->assertTrue($owned->hasLiveDomain());
        $this->assertSame(
            "https://floevent.id/{$org->slug}/plain/tickets",
            $posters->purchaseUrl($plain),
        );
        $this->assertSame(
            "https://floevent.id/{$org->slug}/owned/tickets",
            $posters->purchaseUrl($owned),
        );
        $this->assertStringNotContainsString('mbucup.id', $posters->html($owned));
    }

    public function test_organizer_downloads_a_pdf(): void
    {
        $org = $this->orgWithOwner();
        $event = $this->ticketedEvent($org);

        $response = $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-poster");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Compare two events of the same organization on different plans. Asserting
     * only the refusal would stay green even if the gate read the organization
     * instead of the event — which is the mistake the whole plan surface is
     * built to prevent.
     */
    public function test_gate_is_the_events_plan_not_the_orgs(): void
    {
        $org = $this->orgWithOwner();

        $allowed = $this->eventOn($org, $this->fullPlan(), ['slug' => 'berbayar', 'status' => 'open']);
        $refused = $this->eventOn($org, $this->planWith([], 'Tanpa tiket'), ['slug' => 'gratisan', 'status' => 'open']);

        $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$allowed->id}/ticket-poster")
            ->assertOk();

        $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$refused->id}/ticket-poster")
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'qr_tickets');
    }

    /**
     * A draft event 404s on every public surface (ResolvesPublicEvent), so its
     * QR would be dead on arrival — and a poster is the one thing here that
     * cannot be corrected once it is on a wall.
     */
    public function test_draft_event_is_refused(): void
    {
        $org = $this->orgWithOwner();
        $event = $this->ticketedEvent($org, ['status' => 'draft']);

        $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-poster")
            ->assertStatus(422);
    }

    /** Plain `tenant`, like the team album: no buyer data, no money. */
    public function test_operator_can_print_without_org_admin(): void
    {
        $org = $this->orgWithOwner();
        $event = $this->ticketedEvent($org);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-poster")
            ->assertOk();
    }
}
