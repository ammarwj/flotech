<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The single-fixture endpoint the scoreboard screen polls.
 *
 * It exists next to matchStats(), reads the same slug pair, and answers about
 * one row — which is exactly the shape that hides an authorization hole, so the
 * assertions here are comparisons: a fixture read through its own event against
 * the same fixture read through a neighbouring one, and a category that plays
 * over partai against one that does not. Asserting a single 200 would stay
 * green with the ownership check deleted.
 */
class PublicScoreboardTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function categoryOn(Event $event, array $attrs = []): EventCategory
    {
        return $event->categories()->create([
            'name' => 'Putra',
            'slug' => 'putra-'.uniqid(),
            'tournament_format' => 'league',
            'participant_type' => 'team',
            'registration_fee' => 0,
            'sort_order' => 0,
            ...$attrs,
        ]);
    }

    private function teamOn(Event $event, EventCategory $category, string $name): Team
    {
        return $event->teams()->create([
            'category_id' => $category->id,
            'name' => $name,
            'contact_name' => 'PIC',
            'contact_phone' => '0800',
            'status' => 'approved',
        ]);
    }

    private function url(Event $event, string $matchId): string
    {
        $event->loadMissing('organization');

        return "/api/v1/public/events/{$event->organization->slug}/{$event->slug}/matches/{$matchId}";
    }

    /**
     * The slug pair is the whole of the authorization, so a match has to be
     * read through the event that owns it and no other.
     *
     * Compared rather than asserted alone: a 200 on its own would pass with
     * abort_if() removed, and every match id in the database would then be
     * readable through any published event's URL.
     */
    public function test_a_fixture_is_only_readable_through_its_own_event(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $mine = $this->eventOn($org);
        $neighbour = $this->eventOn($org);

        $category = $this->categoryOn($mine);
        $match = $mine->matches()->create([
            'category_id' => $category->id,
            'round' => 1,
            'order' => 0,
            'status' => 'ongoing',
        ]);

        $own = $this->getJson($this->url($mine, $match->id));
        $through = $this->getJson($this->url($neighbour, $match->id));

        $own->assertOk();
        $this->assertSame($match->id, $own->json('data.match.id'));
        $through->assertNotFound();
    }

    /**
     * A draft is not published, so nothing under it is readable — the same line
     * resolve() draws for the event page itself.
     */
    public function test_a_draft_event_publishes_no_fixture(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $open = $this->eventOn($org);
        $draft = $this->eventOn($org, null, ['status' => 'draft']);

        $openMatch = $open->matches()->create([
            'category_id' => $this->categoryOn($open)->id,
            'round' => 1, 'order' => 0, 'status' => 'ongoing',
        ]);
        $draftMatch = $draft->matches()->create([
            'category_id' => $this->categoryOn($draft)->id,
            'round' => 1, 'order' => 0, 'status' => 'ongoing',
        ]);

        $this->getJson($this->url($open, $openMatch->id))->assertOk();
        $this->getJson($this->url($draft, $draftMatch->id))->assertNotFound();
    }

    /**
     * The live score, as the screen reads it while a match is being played.
     *
     * The heading data rides along in the same payload on purpose: the
     * scoreboard is opened cold in a new tab, and a second round trip for the
     * category name would leave it blank while it waited.
     */
    public function test_the_payload_carries_the_score_and_its_heading(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['name' => 'Liga Jakarta', 'timezone' => 'Asia/Makassar']);
        $category = $this->categoryOn($event, ['name' => 'Putra U-17']);

        $home = $this->teamOn($event, $category, 'Garuda');
        $away = $this->teamOn($event, $category, 'Rajawali');

        $match = $event->matches()->create([
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'round' => 1,
            'order' => 0,
            'status' => 'ongoing',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $res = $this->getJson($this->url($event, $match->id))->assertOk();

        $this->assertSame('Liga Jakarta', $res->json('data.event_name'));
        $this->assertSame('Putra U-17', $res->json('data.category_name'));
        $this->assertSame('Asia/Makassar', $res->json('data.timezone'));
        $this->assertSame('football', $res->json('data.sport.slug'));
        $this->assertSame('ongoing', $res->json('data.match.status'));
        $this->assertSame(2, $res->json('data.match.home_score'));
        $this->assertSame(1, $res->json('data.match.away_score'));
        $this->assertSame('Garuda', $res->json('data.match.home_team.name'));
        $this->assertSame('Rajawali', $res->json('data.match.away_team.name'));
    }

    /**
     * Partai only for a category played over them.
     *
     * Compared against a plain squad category because the branch is a *load*:
     * asserting that the racket tie carries its rubbers would stay green with
     * the condition removed, and every football fixture would then drag two
     * full squads along for a sport with no use for them.
     */
    public function test_partai_ride_along_only_for_a_category_that_plays_over_them(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'badminton']);

        $racket = $this->categoryOn($event, [
            'name' => 'Beregu Putra',
            'rubber_format' => [
                ['label' => 'Tunggal 1', 'type' => 'single'],
                ['label' => 'Ganda 1', 'type' => 'double'],
            ],
        ]);
        $plain = $this->categoryOn($event, ['name' => 'Tunggal Putra', 'participant_type' => 'single']);

        $tie = $event->matches()->create([
            'category_id' => $racket->id,
            'home_team_id' => $this->teamOn($event, $racket, 'Garuda')->id,
            'away_team_id' => $this->teamOn($event, $racket, 'Rajawali')->id,
            'round' => 1, 'order' => 0, 'status' => 'ongoing',
        ]);
        $single = $event->matches()->create([
            'category_id' => $plain->id,
            'home_team_id' => $this->teamOn($event, $plain, 'Dimas')->id,
            'away_team_id' => $this->teamOn($event, $plain, 'Ammar')->id,
            'round' => 1, 'order' => 0, 'status' => 'ongoing',
        ]);

        $tieRes = $this->getJson($this->url($event, $tie->id))->assertOk();
        $singleRes = $this->getJson($this->url($event, $single->id))->assertOk();

        // seedFor() gives the tie one row per template entry when it is created.
        $this->assertCount(2, $tieRes->json('data.match.rubbers'));
        $this->assertSame([], $singleRes->json('data.match.rubbers'));
    }
}
