<?php

namespace Tests\Feature;

use App\Jobs\PurgeMediaJob;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\MediaCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting a row must take its stored file with it.
 *
 * R2 is not configured under phpunit, so MediaCleanupService resolves the same
 * local `public` disk UploadController writes to in development — faking it
 * exercises the real path without touching the network.
 *
 * Every case here asserts a *pair*: what went and what stayed. A collector that
 * is too greedy passes every "the file is gone" assertion on its own.
 */
class MediaCleanupTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Fire the purge jobs the request deferred with afterCommit().
     *
     * Those dispatches are deliberate — a rollback must not leave surviving
     * rows pointing at deleted files — but RefreshDatabase wraps each test in a
     * transaction it rolls back rather than commits, so nothing would ever fire
     * here. Running the callbacks by hand puts the test at the moment
     * production reaches when the request's transaction commits.
     */
    private function commitDeferredJobs(): void
    {
        $this->app->make('db.transactions')
            ->callbackApplicableTransactions()
            ->each
            ->executeCallbacks();
    }

    /** Put a file on the fake disk and return the URL a row would store. */
    private function file(string $key): string
    {
        Storage::disk('public')->put($key, 'x');

        return Storage::disk('public')->url($key);
    }

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org, string $slug = 'media-cup'): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Media Cup',
            'slug' => $slug,
            'sport_type' => 'mini_soccer',
            'tournament_format' => 'league',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
        ]);
    }

    public function test_deleting_an_event_purges_every_file_under_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $banner = $this->file('events/banner.webp');
        $event->update(['banner_url' => $banner]);

        $photo = $this->file('events/photo.webp');
        $event->photos()->create(['photo_url' => $photo]);

        $sponsorLogo = $this->file('sponsors/logo.webp');
        $event->sponsors()->create(['name' => 'Sponsor', 'logo_url' => $sponsorLogo, 'tier' => 'sponsor']);

        $teamLogo = $this->file('teams/logo.webp');
        $teamProof = $this->file('payment-proofs/team.webp');
        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Team A',
            'status' => 'approved',
            'logo_url' => $teamLogo,
            'payment_proof_url' => $teamProof,
        ]);

        $playerPhoto = $this->file('players/one.webp');
        $team->players()->create(['full_name' => 'Pemain', 'photo_url' => $playerPhoto]);

        $officialPhoto = $this->file('officials/one.webp');
        $team->officials()->create(['full_name' => 'Pelatih', 'sort_order' => 0, 'photo_url' => $officialPhoto]);

        $document = $this->file('uploads/ktp.pdf');
        $team->documents()->create(['file_url' => $document, 'file_name' => 'ktp.pdf']);

        $ticketCategory = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000]);
        $orderProof = $this->file('payment-proofs/order.webp');
        TicketOrder::create([
            'event_id' => $event->id,
            'ticket_category_id' => $ticketCategory->id,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
            'status' => 'pending',
            'payment_proof_url' => $orderProof,
        ]);

        // pdf_key is a bare object key, not a URL — the other shape keyFor() takes.
        $pdfKey = 'certificates/one.pdf';
        Storage::disk('public')->put($pdfKey, 'x');

        $templateBackground = $this->file('certificates/background.webp');
        $template = CertificateTemplate::create([
            'organization_id' => $org->id,
            'name' => 'Juara',
            'background_url' => $templateBackground,
            'orientation' => 'landscape',
            'fields' => [],
        ]);

        Certificate::create([
            'organization_id' => $org->id,
            'event_id' => $event->id,
            'certificate_template_id' => $template->id,
            'certificate_number' => 'CERT/1',
            'recipient_type' => 'team',
            'recipient_id' => $team->id,
            'recipient_name' => 'Team A',
            'award_title' => 'Juara 1',
            'pdf_key' => $pdfKey,
            'issued_at' => now(),
        ]);

        // Files that outlive the event, each reachable from it.
        $orgLogo = $this->file('organizations/logo.webp');
        $org->update(['logo_url' => $orgLogo]);

        $otherBanner = $this->file('events/other-banner.webp');
        $this->event($org, 'other-cup')->update(['banner_url' => $otherBanner]);

        // Driven through the service rather than DELETE /events/{id}, because
        // the endpoint now refuses an event carrying teams, ticket orders or
        // certificates — deleting one of those took its own paid history and
        // handed the plan credit back. This test is about the *collector*: given
        // an event that is going away, does it find every file underneath it and
        // nothing outside it. The two questions are separate now, and
        // EventDeletionTest owns the other one.
        $urls = app(MediaCleanupService::class)->eventUrls($event->fresh());
        $event->delete();
        PurgeMediaJob::dispatch($urls)->afterCommit();

        $this->commitDeferredJobs();

        foreach ([$banner, $photo, $sponsorLogo, $teamLogo, $teamProof, $playerPhoto, $officialPhoto, $document, $orderProof] as $url) {
            Storage::disk('public')->assertMissing($this->keyOf($url));
        }
        Storage::disk('public')->assertMissing($pdfKey);

        // The comparison that matters: none of these belong to the event, and a
        // collector scoped by organization_id instead of event_id takes them.
        Storage::disk('public')->assertExists($this->keyOf($orgLogo));
        Storage::disk('public')->assertExists($this->keyOf($templateBackground));
        Storage::disk('public')->assertExists($this->keyOf($otherBanner));
    }

    public function test_deleting_a_photo_purges_only_that_file(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $doomed = $this->file('events/doomed.webp');
        $kept = $this->file('events/kept.webp');

        $photo = $event->photos()->create(['photo_url' => $doomed]);
        $event->photos()->create(['photo_url' => $kept]);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/photos/{$photo->id}")
            ->assertOk();

        $this->commitDeferredJobs();

        Storage::disk('public')->assertMissing($this->keyOf($doomed));
        Storage::disk('public')->assertExists($this->keyOf($kept));
    }

    public function test_deleting_a_sponsor_purges_its_logo(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $logo = $this->file('sponsors/logo.webp');
        $sponsor = $event->sponsors()->create(['name' => 'Sponsor', 'logo_url' => $logo, 'tier' => 'sponsor']);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/sponsors/{$sponsor->id}")
            ->assertOk();

        $this->commitDeferredJobs();

        Storage::disk('public')->assertMissing($this->keyOf($logo));
    }

    /**
     * photo_url is validated as a plain string, so an organizer may point at an
     * image we don't host; and a presigned upload in development stores a
     * mock:// placeholder that was never written. Neither may be turned into a
     * key and aimed at the disk.
     */
    public function test_values_that_are_not_ours_are_left_alone(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $bystander = $this->file('events/bystander.webp');

        $external = $event->photos()->create(['photo_url' => 'https://cdn.example.com/events/bystander.webp']);
        $mock = $event->photos()->create(['photo_url' => 'mock://events/bystander.webp']);

        foreach ([$external, $mock] as $photo) {
            $this->actingAs($user, 'api')
                ->deleteJson("/api/v1/organizations/{$org->id}/photos/{$photo->id}")
                ->assertOk();
        }

        $this->commitDeferredJobs();

        Storage::disk('public')->assertExists($this->keyOf($bystander));
    }

    public function test_pruning_a_roster_purges_dropped_photos_but_not_reused_ones(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Team A',
            'status' => 'approved',
            'manager_user_id' => $user->id,
        ]);

        $dropped = $this->file('players/dropped.webp');
        $moved = $this->file('players/moved.webp');

        $keep = $team->players()->create(['full_name' => 'Tetap', 'photo_url' => null]);
        $team->players()->create(['full_name' => 'Dibuang', 'photo_url' => $dropped]);
        $team->players()->create(['full_name' => 'Pindah', 'photo_url' => $moved]);

        // Both rows carrying a photo are dropped, but one of the photos is
        // re-sent on a row that survives.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$team->id}", [
                'players' => [
                    ['id' => $keep->id, 'full_name' => 'Tetap', 'photo_url' => $moved],
                ],
            ])
            ->assertOk();

        $this->commitDeferredJobs();

        Storage::disk('public')->assertMissing($this->keyOf($dropped));
        Storage::disk('public')->assertExists($this->keyOf($moved));
    }

    public function test_dropping_a_category_purges_the_files_of_its_teams(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $doomed = $event->categories()->create([
            'name' => 'U17', 'slug' => 'u17', 'tournament_format' => 'league', 'sort_order' => 0,
        ]);
        $kept = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'sort_order' => 1,
        ]);

        $doomedLogo = $this->file('teams/doomed.webp');
        $doomedTeam = $event->teams()->create([
            'category_id' => $doomed->id, 'name' => 'U17 A', 'status' => 'approved', 'logo_url' => $doomedLogo,
        ]);
        $doomedPhoto = $this->file('players/doomed.webp');
        $doomedTeam->players()->create(['full_name' => 'Pemain', 'photo_url' => $doomedPhoto]);

        $keptLogo = $this->file('teams/kept.webp');
        $event->teams()->create([
            'category_id' => $kept->id, 'name' => 'Umum A', 'status' => 'approved', 'logo_url' => $keptLogo,
        ]);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}", [
                'name' => 'Media Cup',
                'sport_type' => 'mini_soccer',
                'tournament_format' => 'league',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-10',
                'categories' => [
                    ['id' => $kept->id, 'name' => 'Umum', 'tournament_format' => 'league'],
                ],
            ])
            ->assertOk();

        $this->commitDeferredJobs();

        $this->assertNull(Team::find($doomedTeam->id));

        Storage::disk('public')->assertMissing($this->keyOf($doomedLogo));
        Storage::disk('public')->assertMissing($this->keyOf($doomedPhoto));
        Storage::disk('public')->assertExists($this->keyOf($keptLogo));
    }

    /** The key a stored URL points at, so assertions read like the fixtures. */
    private function keyOf(string $url): string
    {
        return ltrim(str_replace(rtrim(Storage::disk('public')->url(''), '/'), '', $url), '/');
    }
}
