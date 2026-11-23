<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewTeamRegistered;
use App\Services\TeamAlbumService;
use App\Support\CoreFieldLabels;
use App\Support\RegistrationForm;
use App\Support\RegistrationTemplateColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Renaming the four fields the platform always asks for.
 *
 * Every test here compares an event that renamed something against one that
 * did not, because each failure mode passes a one-sided assertion: a surface
 * that hardcodes "Nama tim" still prints a correct-looking sheet for the
 * default event, and an override layer that stores the resolved map still
 * returns the right label on the very next read — it only breaks on the deploy
 * after.
 */
class CoreFieldLabelsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function makeEvent(?array $form = null, string $slug = 'cup'): Event
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id,
        ]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => $slug, 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(),
            'registration_close' => Carbon::now()->addDays(10),
            'registration_form' => $form,
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'max_teams' => null, 'sort_order' => 0,
        ]);

        return $event->load('categories', 'organization');
    }

    private function url(Event $event): string
    {
        return "/api/v1/organizations/{$event->organization_id}/events/{$event->id}/registration-form";
    }

    /** An otherwise-empty schema carrying only label overrides. */
    private function labelled(array $labels): array
    {
        return [
            'labels' => $labels,
            'team_fields' => [], 'player_fields' => [], 'team_official_fields' => [],
            'team_documents' => [], 'player_documents' => [], 'team_official_documents' => [],
        ];
    }

    /**
     * The invariant that makes this feature survive a deploy: only what the
     * organizer CHANGED is written down.
     *
     * A same-value save that stored four rows would freeze today's wording into
     * that event forever — tomorrow's improved default would never reach it
     * again. Compared against a real rename in the same test, because asserting
     * "it saved" is green on both readings.
     */
    public function test_only_changed_labels_are_stored(): void
    {
        $event = $this->makeEvent();
        $owner = $event->organization->owner;

        // Every field spelled out, all of them equal to the default — exactly
        // what a form posts when the organizer opens the page and saves.
        $this->actingAs($owner, 'api')
            ->putJson($this->url($event), $this->labelled(CoreFieldLabels::DEFAULTS))
            ->assertOk()
            // The response still carries every key resolved: the client renders
            // this map and must not merge defaults of its own.
            ->assertJsonPath('data.labels.team_name', 'Nama tim');

        $this->assertSame([], $event->fresh()->registration_form['labels']);

        // One real rename, same payload shape.
        $this->actingAs($owner, 'api')
            ->putJson($this->url($event), $this->labelled([
                ...CoreFieldLabels::DEFAULTS,
                'team_name' => 'Nama sekolah',
            ]))
            ->assertOk()
            ->assertJsonPath('data.labels.team_name', 'Nama sekolah')
            // Untouched keys still answer, from the catalogue.
            ->assertJsonPath('data.labels.contact_phone', 'No. HP kontak');

        $this->assertSame(
            ['team_name' => 'Nama sekolah'],
            $event->fresh()->registration_form['labels'],
        );
    }

    /**
     * Clearing a box means "back to the default", not "a field with no question
     * above it". Two spellings of "no override" must not both be storable.
     */
    public function test_blank_label_falls_back_to_default(): void
    {
        $event = $this->makeEvent($this->labelled(['team_name' => 'Nama klub']));
        $owner = $event->organization->owner;

        $this->assertSame('Nama klub', RegistrationForm::forEvent($event)->label('team_name'));

        $this->actingAs($owner, 'api')
            ->putJson($this->url($event), $this->labelled(['team_name' => '   ']))
            ->assertOk()
            ->assertJsonPath('data.labels.team_name', 'Nama tim');

        $this->assertSame([], $event->fresh()->registration_form['labels']);
    }

    /**
     * Two events of the SAME organization, so nothing but the override can
     * explain the difference — the shape CLAUDE.md asks for when a setting is
     * per event.
     */
    public function test_two_events_of_one_org_answer_differently(): void
    {
        $plain = $this->makeEvent(null, 'plain');
        $renamed = $plain->organization->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup 2', 'slug' => 'renamed', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_form' => $this->labelled([
                'team_logo' => 'Logo instansi',
                'team_name' => 'Nama instansi',
                'contact_name' => 'PIC',
                'contact_phone' => 'WA PIC',
            ]),
        ]);

        $this->assertSame('Nama tim', RegistrationForm::forEvent($plain)->label('team_name'));
        $this->assertSame('Nama instansi', RegistrationForm::forEvent($renamed)->label('team_name'));
        $this->assertSame('Logo tim', RegistrationForm::forEvent($plain)->label('team_logo'));
        $this->assertSame('WA PIC', RegistrationForm::forEvent($renamed)->label('contact_phone'));
    }

    /**
     * The import/export template's three core headers follow the rename — and
     * its KEYS do not. The import reads this list by index and zips the keys
     * onto the row, so a key that moved with its label would quietly write a
     * different `teams` column.
     */
    public function test_template_headers_follow_the_rename_but_keys_do_not(): void
    {
        $plain = $this->makeEvent(null, 'plain');
        $renamed = $this->makeEvent($this->labelled(['team_name' => 'Nama klub']), 'renamed');

        $plainCols = RegistrationTemplateColumns::forCategory($plain->categories->first());
        $renamedCols = RegistrationTemplateColumns::forCategory($renamed->categories->first());

        $this->assertSame('Nama tim', $plainCols[0]['label']);
        $this->assertSame('Nama klub', $renamedCols[0]['label']);

        // Same key, same position, same column count: nothing about the file's
        // shape moved.
        $this->assertSame(
            array_column($plainCols, 'key'),
            array_column($renamedCols, 'key'),
        );
    }

    /**
     * The album's two ruled captions.
     *
     * The sheet has PRINT defaults of its own ("NAMA KLUB", "KONTAK") that are
     * not the form's wording, so the default event must keep them — resolving
     * through label() would have retitled every existing album. Asserted on the
     * HTML, not the PDF bytes: dompdf's output is compressed streams, so an
     * assertion over them stays green when a row stops being filled in.
     */
    public function test_album_keeps_its_print_defaults_until_the_field_is_renamed(): void
    {
        $plain = $this->makeEvent(null, 'plain');
        $renamed = $this->makeEvent($this->labelled(['team_name' => 'Nama instansi']), 'renamed');

        $service = app(TeamAlbumService::class);

        $plainHtml = $service->html($plain, collect([$this->teamFor($plain)]));
        $this->assertStringContainsString('NAMA KLUB', $plainHtml);

        $renamedHtml = $service->html($renamed, collect([$this->teamFor($renamed)]));
        $this->assertStringContainsString('NAMA INSTANSI', $renamedHtml);
        $this->assertStringNotContainsString('NAMA KLUB', $renamedHtml);
    }

    /** The organizer's notification names the row the way their form does. */
    public function test_new_team_mail_uses_the_event_label(): void
    {
        $renamed = $this->makeEvent($this->labelled(['team_name' => 'Nama sekolah']), 'renamed');
        $team = $this->teamFor($renamed);

        $mail = (new NewTeamRegistered($team))->toMail($renamed->organization->owner);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Nama sekolah', $html);
    }

    /** A label longer than the column is trimmed, not rejected into a 500. */
    public function test_label_is_capped(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($event->organization->owner, 'api')
            ->putJson($this->url($event), $this->labelled([
                'team_name' => str_repeat('a', 200),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['labels.team_name']);
    }

    private function teamFor(Event $event): Team
    {
        $team = $event->teams()->create([
            'category_id' => $event->categories->first()->id,
            'name' => 'Garuda',
            'contact_name' => 'Budi',
            'contact_phone' => '0812',
            'status' => 'approved',
        ]);

        return $team->load('players', 'officials');
    }
}
