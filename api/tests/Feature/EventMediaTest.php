<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Photo albums and sponsor logos on an event. */
class EventMediaTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org): Event
    {
        return $org->events()->create([
            'name' => 'Media Cup',
            'slug' => 'media-cup',
            'sport_type' => 'mini_soccer',
            'tournament_format' => 'league',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
        ]);
    }

    public function test_photos_are_added_in_bulk_and_grouped_by_album(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/photos", [
                'album' => 'Opening Ceremony',
                'photos' => [
                    ['photo_url' => 'https://cdn.test/1.webp', 'caption' => 'Kick off'],
                    ['photo_url' => 'https://cdn.test/2.webp'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, $event->photos()->where('album', 'Opening Ceremony')->count());

        // Sort order is assigned per album, so the gallery keeps upload order.
        $this->assertSame([1, 2], $event->photos()->pluck('sort_order')->all());
    }

    public function test_photo_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $photo = $event->photos()->create(['photo_url' => 'https://cdn.test/1.webp']);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('event_photos', ['id' => $photo->id]);
    }

    public function test_sponsor_is_created_with_a_tier(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/sponsors", [
                'name' => 'Bank Jogja',
                'logo_url' => 'https://cdn.test/logo.webp',
                'website_url' => 'https://bankjogja.test',
                'tier' => 'media_partner',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tier', 'media_partner');

        // No tier given → a plain sponsor.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/sponsors", [
                'name' => 'Toko Bola',
                'logo_url' => 'https://cdn.test/bola.webp',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tier', 'sponsor');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/sponsors", [
                'name' => 'Warung Kopi',
                'logo_url' => 'https://cdn.test/kopi.webp',
                'tier' => 'invalid-tier',
            ])
            ->assertStatus(422);
    }

    public function test_public_event_page_exposes_photos_and_sponsors(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $event->photos()->create(['photo_url' => 'https://cdn.test/1.webp', 'album' => 'Final']);
        $event->sponsors()->create([
            'name' => 'Bank Jogja',
            'logo_url' => 'https://cdn.test/logo.webp',
            'tier' => 'host',
        ]);

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.photos.0.album', 'Final')
            ->assertJsonPath('data.sponsors.0.name', 'Bank Jogja');
    }

    public function test_another_organization_cannot_touch_the_media(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($this->org($owner));
        $photo = $event->photos()->create(['photo_url' => 'https://cdn.test/1.webp']);

        $intruder = User::factory()->create();
        $otherOrg = $this->org($intruder);

        $this->actingAs($intruder, 'api')
            ->deleteJson("/api/v1/organizations/{$otherOrg->id}/photos/{$photo->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('event_photos', ['id' => $photo->id]);
    }
}
