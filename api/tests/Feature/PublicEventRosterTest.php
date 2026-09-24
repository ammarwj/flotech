<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The squad list on the public event page.
 *
 * Every assertion here is a comparison between two teams, because the thing
 * under test is a *derivation*: a category name printed against the wrong squad
 * — or the same one printed against both — reads perfectly fine on its own, and
 * a single-team assert would pass even if the eager load never ran and every
 * roster came back with whatever category happened to be attached last.
 */
class PublicEventRosterTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** An event running two categories at once — the case the dialog exists for. */
    private function eventWithTwoCategories(): Event
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'football']);

        $event->categories()->create([
            'name' => 'Putra U-17', 'slug' => 'putra-u17', 'tournament_format' => 'hybrid',
            'participant_type' => 'team', 'registration_fee' => 0, 'sort_order' => 0,
        ]);
        $event->categories()->create([
            'name' => 'Putri Umum', 'slug' => 'putri-umum', 'tournament_format' => 'league',
            'participant_type' => 'double', 'registration_fee' => 0, 'sort_order' => 1,
        ]);

        return $event->load(['categories', 'organization']);
    }

    private function showUrl(Event $event): string
    {
        return "/api/v1/public/events/{$event->organization->slug}/{$event->slug}";
    }

    public function test_each_squad_carries_the_category_it_entered(): void
    {
        $event = $this->eventWithTwoCategories();
        [$putra, $putri] = [$event->categories[0], $event->categories[1]];

        // Names chosen so the ordering is known: the endpoint sorts by name.
        $event->teams()->create([
            'category_id' => $putra->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081', 'group_name' => 'B',
        ]);
        $event->teams()->create([
            'category_id' => $putri->id, 'name' => 'Beta FC', 'status' => 'approved',
            'contact_name' => 'B', 'contact_phone' => '082',
        ]);

        $teams = $this->getJson($this->showUrl($event))
            ->assertOk()
            ->json('data.approved_teams');

        $this->assertCount(2, $teams);

        // The comparison that matters: two squads, two different categories.
        // Asserting one of them alone passes even when every row is stamped
        // with the same relation.
        $this->assertSame('putra-u17', $teams[0]['category']['slug']);
        $this->assertSame('putri-umum', $teams[1]['category']['slug']);
        $this->assertNotSame($teams[0]['category']['slug'], $teams[1]['category']['slug']);

        // participant_type is what tells the roster whether it is showing a
        // squad, a pair or a lone player — so it has to differ too.
        $this->assertSame('team', $teams[0]['category']['participant_type']);
        $this->assertSame('double', $teams[1]['category']['participant_type']);

        // Group is per team, not per category: drawn into B, and null for the
        // league side that has no groups at all.
        $this->assertSame('B', $teams[0]['group_name']);
        $this->assertNull($teams[1]['group_name']);
    }

    public function test_roster_publishes_the_on_pitch_fields_and_nothing_else(): void
    {
        $event = $this->eventWithTwoCategories();

        $team = $event->teams()->create([
            'category_id' => $event->categories[0]->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'Andi', 'contact_phone' => '08123456789',
        ]);
        $team->players()->create([
            'full_name' => 'Player One', 'jersey_number' => '10',
            'position' => 'forward', 'photo_url' => 'https://cdn.test/p1.webp',
            'date_of_birth' => '2001-01-01',
        ]);
        $team->officials()->create([
            'full_name' => 'Coach Budi', 'role' => 'head_coach', 'sort_order' => 0,
        ]);

        $row = $this->getJson($this->showUrl($event))->assertOk()->json('data.approved_teams.0');

        $this->assertSame('Player One', $row['players'][0]['full_name']);
        $this->assertSame('https://cdn.test/p1.webp', $row['players'][0]['photo_url']);
        $this->assertSame('Coach Budi', $row['officials'][0]['full_name']);

        // The trim is the point of the resource. Compared against the fields
        // above so this fails loudly if the map is ever replaced by the model.
        $this->assertArrayNotHasKey('date_of_birth', $row['players'][0]);
        $this->assertArrayNotHasKey('contact_phone', $row);

        // custom_fields is present but empty: this event defined no form, so
        // there is nothing published. Absent and empty are not the same thing
        // — the client renders the section from the list's length.
        $this->assertSame([], $row['custom_fields']);
        $this->assertSame([], $row['players'][0]['custom_fields']);
        $this->assertSame([], $row['officials'][0]['custom_fields']);
    }

    public function test_roster_is_ordered_by_shirt_number_with_unnumbered_players_last(): void
    {
        $event = $this->eventWithTwoCategories();

        $team = $event->teams()->create([
            'category_id' => $event->categories[0]->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081',
        ]);

        // Inserted out of order on purpose, and alphabetically backwards, so
        // neither insertion order nor the name tiebreaker can produce the
        // expected list by accident.
        foreach ([
            ['Zulkifli', '9'],
            ['Yusuf', '10'],
            ['Xaverius', null],
            ['Wawan', 'GK-2'],
            ['Vino', '2'],
        ] as [$name, $jersey]) {
            $team->players()->create(['full_name' => $name, 'jersey_number' => $jersey]);
        }

        $players = $this->getJson($this->showUrl($event))
            ->assertOk()
            ->json('data.approved_teams.0.players');

        // 10 after 9, not before it: the comparison that separates a numeric
        // sort from a string one. And the two players whose number is not a
        // number sink to the bottom together, in name order.
        $this->assertSame(
            ['Vino', 'Zulkifli', 'Yusuf', 'Wawan', 'Xaverius'],
            array_column($players, 'full_name'),
        );
    }

    /**
     * The flag is the whole feature, so every assertion here is a pair: one
     * field marked public and one not, in the same request, on the same person.
     * Asserting only that the public one arrives passes even when the filter
     * never runs and everything is published.
     */
    public function test_only_fields_marked_public_are_published(): void
    {
        $event = $this->eventWithTwoCategories();

        $event->update(['registration_form' => [
            'team_fields' => [
                ['key' => 'asal_sekolah', 'label' => 'Asal Sekolah', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
                ['key' => 'alamat', 'label' => 'Alamat Sekretariat', 'type' => 'short_text', 'required' => false, 'is_public' => false, 'options' => []],
            ],
            'player_fields' => [
                ['key' => 'tinggi', 'label' => 'Tinggi Badan', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
                ['key' => 'no_ktp', 'label' => 'No. KTP', 'type' => 'short_text', 'required' => false, 'is_public' => false, 'options' => []],
            ],
            'team_official_fields' => [
                ['key' => 'lisensi', 'label' => 'No. Lisensi', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
                ['key' => 'no_hp', 'label' => 'No. HP', 'type' => 'short_text', 'required' => false, 'is_public' => false, 'options' => []],
            ],
            'team_documents' => [], 'player_documents' => [], 'team_official_documents' => [],
        ]]);

        $team = $event->teams()->create([
            'category_id' => $event->categories[0]->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081',
            'custom_fields' => ['asal_sekolah' => 'SMA 1', 'alamat' => 'Jl. Rahasia 5'],
        ]);
        $team->players()->create([
            'full_name' => 'Player One', 'jersey_number' => '10',
            'custom_fields' => ['tinggi' => '178 cm', 'no_ktp' => '3201234567890001'],
        ]);
        $team->officials()->create([
            'full_name' => 'Coach Budi', 'role' => 'head_coach', 'sort_order' => 0,
            'custom_fields' => ['lisensi' => 'C-1234', 'no_hp' => '08111222333'],
        ]);

        $row = $this->getJson($this->showUrl($event))->assertOk()->json('data.approved_teams.0');

        // All three scopes, each a pair. The published answer arrives with its
        // label; the private one is not in the payload at all — not blanked,
        // not flagged, absent, so no component can render it by mistake.
        $this->assertSame(
            [['key' => 'asal_sekolah', 'label' => 'Asal Sekolah', 'value' => 'SMA 1']],
            $row['custom_fields'],
        );
        $this->assertSame(
            [['key' => 'tinggi', 'label' => 'Tinggi Badan', 'value' => '178 cm']],
            $row['players'][0]['custom_fields'],
        );
        $this->assertSame(
            [['key' => 'lisensi', 'label' => 'No. Lisensi', 'value' => 'C-1234']],
            $row['officials'][0]['custom_fields'],
        );

        // Said again against the raw body, because the assertions above would
        // also hold if a private answer rode along under some other key.
        $body = $this->getJson($this->showUrl($event))->getContent();
        $this->assertStringContainsString('SMA 1', $body);
        $this->assertStringNotContainsString('Jl. Rahasia 5', $body);
        $this->assertStringNotContainsString('3201234567890001', $body);
        $this->assertStringNotContainsString('08111222333', $body);
    }

    public function test_a_field_saved_before_the_flag_existed_stays_private(): void
    {
        $event = $this->eventWithTwoCategories();

        // Exactly what an older schema looks like: no is_public key at all.
        // Absent has to read as private, or shipping this feature would
        // publish answers given to a form that never offered to.
        $event->update(['registration_form' => [
            'team_fields' => [
                ['key' => 'alamat', 'label' => 'Alamat', 'type' => 'short_text', 'required' => false, 'options' => []],
            ],
            'player_fields' => [], 'team_official_fields' => [],
            'team_documents' => [], 'player_documents' => [], 'team_official_documents' => [],
        ]]);

        $event->teams()->create([
            'category_id' => $event->categories[0]->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081',
            'custom_fields' => ['alamat' => 'Jl. Lama 9'],
        ]);

        $response = $this->getJson($this->showUrl($event))->assertOk();

        $this->assertSame([], $response->json('data.approved_teams.0.custom_fields'));
        $this->assertStringNotContainsString('Jl. Lama 9', $response->getContent());

        // The organizer's own copy of the schema still round-trips the row —
        // the flag defaults, it does not drop the field.
        $this->assertSame('alamat', $response->json('data.registration_form.team_fields.0.key'));
        $this->assertFalse($response->json('data.registration_form.team_fields.0.is_public'));
    }

    public function test_a_public_field_left_unanswered_is_not_listed(): void
    {
        $event = $this->eventWithTwoCategories();

        $event->update(['registration_form' => [
            'team_fields' => [
                ['key' => 'asal_sekolah', 'label' => 'Asal Sekolah', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
                ['key' => 'julukan', 'label' => 'Julukan', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
            ],
            'player_fields' => [], 'team_official_fields' => [],
            'team_documents' => [], 'player_documents' => [], 'team_official_documents' => [],
        ]]);

        // Two public fields, one answered. The comparison is what proves the
        // blank is skipped rather than rendered as an empty row under a label,
        // which reads as missing data instead of an optional question.
        $event->teams()->create([
            'category_id' => $event->categories[0]->id, 'name' => 'Alpha FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081',
            'custom_fields' => ['asal_sekolah' => 'SMA 1', 'julukan' => ''],
        ]);

        $answers = $this->getJson($this->showUrl($event))
            ->assertOk()
            ->json('data.approved_teams.0.custom_fields');

        $this->assertSame(['asal_sekolah'], array_column($answers, 'key'));
    }

    public function test_only_approved_squads_are_listed(): void
    {
        $event = $this->eventWithTwoCategories();
        $category = $event->categories[0];

        $event->teams()->create([
            'category_id' => $category->id, 'name' => 'Approved FC', 'status' => 'approved',
            'contact_name' => 'A', 'contact_phone' => '081',
        ]);
        // Same category, same shape — the status is the only difference, which
        // is what makes this a comparison rather than a count.
        $event->teams()->create([
            'category_id' => $category->id, 'name' => 'Pending FC', 'status' => 'pending',
            'contact_name' => 'B', 'contact_phone' => '082',
        ]);

        $teams = $this->getJson($this->showUrl($event))->assertOk()->json('data.approved_teams');

        $this->assertSame(['Approved FC'], array_column($teams, 'name'));
    }
}
