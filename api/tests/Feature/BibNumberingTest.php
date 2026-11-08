<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Services\BibService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * `race_entries.bib_number` finally has a writer, and this file is about the
 * one thing that writer must never do: change a number it already gave out.
 *
 * Every case **compares** two states, because almost nothing here can be proved
 * with a single assert. "Every entry has a number" stays green on a service
 * that renumbers the whole field on every run. "The second batch assigned
 * numbers" stays green when it assigned the same ones twice. "Numbering
 * worked" stays green when it numbered across categories and two runners wear
 * 001.
 */
class BibNumberingTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-05 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): BibService
    {
        return app(BibService::class);
    }

    private function raceEvent(Organization $org): Event
    {
        return $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
        ]);
    }

    private function category(Event $event, string $name, int $sort = 0): RaceCategory
    {
        return $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sort,
        ]);
    }

    /**
     * An entry written straight to the table: this file is about the numbering
     * rule, not about the checkout that produces a row.
     */
    private function entry(
        Event $event,
        RaceCategory $category,
        string $name,
        string $status = 'paid',
        ?string $bib = null,
        ?string $createdAt = null,
    ): RaceEntry {
        return RaceEntry::create([
            'event_id' => $event->id,
            'race_category_id' => $category->id,
            'full_name' => $name,
            'email' => Str::slug($name).'@test.com',
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => $status,
            'payment_method' => 'gateway',
            'bib_number' => $bib,
            'qr_code' => $status === 'paid' ? 'RUN'.Str::upper(Str::random(8)) : null,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    /**
     * Invariant: a number is assigned once and then never changes.
     *
     * The comparison is the whole test. Asserting that every entry carries a
     * number after the second run passes identically on a service that
     * reassigns the entire field — which would mean the paper already handed
     * out at the race-pack desk no longer matches the start list.
     */
    public function test_a_second_run_leaves_every_existing_number_untouched(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event, '5K');

        $first = $this->entry($event, $category, 'Ammar', createdAt: '2026-01-01 00:00:00');
        $second = $this->entry($event, $category, 'Dimas', createdAt: '2026-01-02 00:00:00');

        $this->assertSame(2, $this->service()->assignNumbers($category));

        $before = [$first->fresh()->bib_number, $second->fresh()->bib_number];

        // A third runner arrives, then the organizer prints again.
        $third = $this->entry($event, $category, 'Budi', createdAt: '2026-01-03 00:00:00');

        // One newly numbered row, not three: the return value is what tells the
        // organizer how many bibs are new, and a service that renumbered
        // everything would report 3 here while still passing any "they all
        // have numbers" assertion.
        $this->assertSame(1, $this->service()->assignNumbers($category));

        $after = [$first->fresh()->bib_number, $second->fresh()->bib_number];

        $this->assertSame($before, $after);
        $this->assertSame(['5K-001', '5K-002'], $after);
        // The sequence continues rather than restarting over the top of them.
        $this->assertSame('5K-003', $third->fresh()->bib_number);
    }

    /**
     * Invariant: the sequence belongs to the CATEGORY, not the event.
     *
     * "5K-001" and "10K-001" are two different runners, which is how a start
     * list reads. Numbering per event would hand the second category 003/004
     * and a test looking at one category alone would never see it.
     */
    public function test_each_category_numbers_from_one(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $fiveK = $this->category($event, '5K', 1);
        $tenK = $this->category($event, '10K', 2);

        $a = $this->entry($event, $fiveK, 'Ammar');
        $b = $this->entry($event, $fiveK, 'Dimas');
        $c = $this->entry($event, $tenK, 'Budi');

        $this->service()->assignNumbers($fiveK);
        $this->service()->assignNumbers($tenK);

        $this->assertSame(['5K-001', '5K-002'], [$a->fresh()->bib_number, $b->fresh()->bib_number]);
        // Both halves matter: the stem comes from this category's own slug, and
        // the sequence restarts rather than continuing from the other one.
        $this->assertSame('10K-001', $c->fresh()->bib_number);
    }

    /**
     * A number the organizer typed by hand is kept, and the generated sequence
     * continues past it rather than colliding with it.
     *
     * `UpdateRaceEntryRequest` already accepts `bib_number`, and uniqueness is
     * deliberately not enforced in the database — so walking over a hand-typed
     * number, or reissuing it, would both be invisible failures.
     */
    public function test_a_hand_typed_number_is_kept_and_the_sequence_continues_past_it(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event, '5K');

        $manual = $this->entry($event, $category, 'Ammar', bib: '5K-007');
        $generated = $this->entry($event, $category, 'Dimas');

        $this->assertSame(1, $this->service()->assignNumbers($category));

        $this->assertSame('5K-007', $manual->fresh()->bib_number);
        $this->assertSame('5K-008', $generated->fresh()->bib_number);
    }

    /**
     * Invariant: only a PAID entry is numbered.
     *
     * Compared against a paid row in the same category, because asserting the
     * paid one got a number passes identically on a service that numbers
     * everything — and a bib printed for an abandoned checkout is a race-pack
     * ticket for somebody who never paid.
     */
    public function test_unpaid_entries_are_not_numbered(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum');

        $pending = $this->entry($event, $category, 'Dimas', status: 'pending');
        $paid = $this->entry($event, $category, 'Ammar', status: 'paid');

        $this->assertSame(1, $this->service()->assignNumbers($category));

        $this->assertNull($pending->fresh()->bib_number);
        $this->assertSame('UMUM-001', $paid->fresh()->bib_number);
    }

    /**
     * The stem is derived at ASSIGN time and never read back, so renaming the
     * category leaves issued numbers exactly as they were printed — the same
     * snapshot discipline as `period_name` beside `price`.
     */
    public function test_renaming_the_category_does_not_rewrite_issued_numbers(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event, '5K');

        $old = $this->entry($event, $category, 'Ammar');
        $this->service()->assignNumbers($category);

        $category->update(['name' => 'Half Marathon', 'slug' => 'half-marathon']);
        $new = $this->entry($event, $category, 'Dimas');
        $this->service()->assignNumbers($category->fresh());

        // The first keeps the stem it was printed under; the second gets the
        // new one and starts its own sequence, because the old stem's highest
        // row is not in this prefix.
        $this->assertSame('5K-001', $old->fresh()->bib_number);
        $this->assertSame('HALFMARATHON-001', $new->fresh()->bib_number);
    }
}
