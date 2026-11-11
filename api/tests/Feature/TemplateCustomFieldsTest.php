<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\IdCardService;
use App\Services\R2StorageService;
use App\Support\TemplateFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Registration answers, placed on an ID card and on a certificate.
 *
 * The thing under test is a JOIN between two halves that could not see each
 * other: a fixed catalogue in config, and whatever the organizer asked on the
 * event's own form. So every case here COMPARES — a card that prints the fixed
 * fields and nothing else satisfies "the name is on it", and a catalogue that
 * answers the same list for every event satisfies "the custom field is in
 * there".
 */
class TemplateCustomFieldsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // CertificateService::store() parks the rendered PDF through
        // R2StorageService, which talks to the S3 client directly rather than
        // through Storage — tests/bootstrap.php blanks the credentials, so the
        // call throws. Swapped out because what is under test here is which
        // values the document carries, not where its bytes land.
        $this->instance(R2StorageService::class, new class extends R2StorageService
        {
            public function __construct() {}

            public function put(string $key, string $contents, ?string $contentType = null, bool $immutable = false): void {}
        });
    }

    /**
     * An event whose form asks one question of the team and one of each player.
     */
    private function eventAsking(Organization $org, string $teamKey, string $playerKey): Event
    {
        return $this->eventOn($org, null, [
            'registration_form' => [
                'team_fields' => [
                    ['key' => $teamKey, 'label' => 'Asal Kota', 'type' => 'short_text'],
                ],
                'player_fields' => [
                    ['key' => $playerKey, 'label' => 'Ukuran Jersey', 'type' => 'short_text'],
                ],
            ],
        ]);
    }

    /** An approved team with one player, both carrying answers. */
    private function squad(Event $event, array $teamAnswers, array $playerAnswers): Team
    {
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Garuda FC',
            'status' => 'approved',
            'custom_fields' => $teamAnswers,
        ]);

        $team->players()->create([
            'full_name' => 'Ahmad Fauzi',
            'jersey_number' => 10,
            'custom_fields' => $playerAnswers,
        ]);

        return $team;
    }

    /**
     * The catalogue answers for the EVENT that was asked, not for the
     * organization in general.
     *
     * Compared across two events of one org, because that is the only way to
     * see the difference: an endpoint that ignores `event_id` and returns the
     * union of everything would pass a single-event assertion whole.
     */
    public function test_field_catalogue_follows_the_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $a = $this->eventAsking($org, 'asal_kota', 'ukuran_jersey');
        $b = $this->eventAsking($org, 'nama_sekolah', 'golongan_darah');

        $this->actingAs($user, 'api');

        $keysFor = fn (?Event $event) => collect(
            $this->getJson("/api/v1/organizations/{$org->id}/id-card-fields"
                .($event ? "?event_id={$event->id}" : ''))
                ->assertOk()
                ->json('data')
        )->pluck('key')->all();

        $forA = $keysFor($a);
        $forB = $keysFor($b);
        $plain = $keysFor(null);

        // Each event's own questions, and NOT the other's.
        $this->assertContains('custom:team:asal_kota', $forA);
        $this->assertNotContains('custom:team:nama_sekolah', $forA);

        $this->assertContains('custom:team:nama_sekolah', $forB);
        $this->assertNotContains('custom:team:asal_kota', $forB);

        // The fixed half is in all three; without an event, only the fixed half.
        $this->assertContains('name', $plain);
        $this->assertContains('name', $forA);
        $this->assertSame([], array_filter($plain, fn (string $k) => str_starts_with($k, 'custom:')));

        // Player questions come through their own namespace, so a team question
        // and a player question keyed the same can never collide.
        $this->assertContains('custom:player:ukuran_jersey', $forA);
    }

    /**
     * A key no form anywhere asked is refused at save time, while a key from a
     * DIFFERENT event of the same org is accepted.
     *
     * Both halves in one test on purpose. "A typo is a 422" passes equally if
     * the check were narrowed to the one event open in the editor — which would
     * break every reused template, the thing org-scoping exists for.
     */
    public function test_save_refuses_unknown_keys_but_accepts_another_events_question(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $this->eventAsking($org, 'asal_kota', 'ukuran_jersey');
        $this->eventAsking($org, 'nama_sekolah', 'golongan_darah');

        $this->actingAs($user, 'api');

        $payload = fn (string $key) => [
            'name' => 'Kartu',
            'background_url' => 'https://cdn.test/bg.png',
            'width_mm' => 85.6,
            'height_mm' => 54,
            'fields' => [['key' => $key, 'x' => 50, 'y' => 50, 'size' => 5]],
        ];

        // A question from the OTHER event: valid, because templates are
        // org-scoped and reused.
        $this->postJson("/api/v1/organizations/{$org->id}/id-card-templates", $payload('custom:team:nama_sekolah'))
            ->assertCreated();

        // A typo of it: refused, with the key it meant.
        $refused = $this->postJson(
            "/api/v1/organizations/{$org->id}/id-card-templates",
            $payload('custom:team:nama_sekola'),
        )->assertStatus(422);

        // Flattened and searched rather than read at one path: what matters is
        // that the message names the key they meant, not which envelope shape
        // carried it.
        $this->assertStringContainsString(
            'custom:team:nama_sekolah',
            json_encode($refused->json('errors'), JSON_UNESCAPED_SLASHES) ?: '',
        );

        // And nothing was written for the refusal — compared against the one
        // row the accepted save made, so "422" alone cannot carry this test.
        $this->assertSame(1, $org->idCardTemplates()->count());
    }

    /**
     * What one card prints: a custom answer beside a fixed field, compared with
     * an entrant who left the same question blank.
     *
     * Rendering is checked through recipients(), the array the renderer is
     * handed, rather than through PNG bytes — an encoded image is a compressed
     * stream and an assertion over it stays green when the value vanishes, the
     * same reason ParticipantDocumentService::html() is public.
     */
    public function test_recipients_carry_answers_and_row_columns(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventAsking($org, 'asal_kota', 'ukuran_jersey');

        $this->squad($event, ['asal_kota' => 'Medan'], ['ukuran_jersey' => 'L']);
        $this->squad($event, [], []);

        $people = collect(app(IdCardService::class)->recipients($event))
            ->where('type', 'player')
            ->values();

        $this->assertCount(2, $people);

        $answered = $people->firstWhere('values.custom:player:ukuran_jersey', 'L');
        $this->assertNotNull($answered, 'the player who answered should carry their answer');

        // The team's answer reaches a player's card too — a squad question is a
        // fact about everyone in it.
        $this->assertSame('Medan', $answered['values']['custom:team:asal_kota']);

        // A column the row already carried but no template could print before.
        $this->assertSame('10', $answered['values']['jersey_number']);

        // The comparison that matters: the entrant who answered nothing carries
        // no key at all, rather than an empty string. A builder that emitted a
        // blank for every question would pass the assertions above.
        $blank = $people->first(fn (array $p) => ! isset($p['values']['custom:player:ukuran_jersey']));
        $this->assertNotNull($blank);
        $this->assertArrayNotHasKey('custom:team:asal_kota', $blank['values']);
    }

    /**
     * A consent answer prints as a date, not as the ISO instant it is stored
     * as — the same formatter the public page and the printed album use.
     */
    public function test_consent_answers_print_through_the_shared_formatter(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $event = $this->eventOn($org, null, [
            'registration_form' => [
                'player_fields' => [
                    ['key' => 'setuju', 'label' => 'Pernyataan', 'type' => 'consent'],
                ],
            ],
        ]);

        $values = TemplateFields::values($event, [
            'player' => ['setuju' => '2026-10-17T09:14:02+07:00'],
        ]);

        $this->assertStringStartsWith('Ya · ', $values['custom:player:setuju']);
        $this->assertStringNotContainsString('T09:14', $values['custom:player:setuju']);
    }

    /**
     * A certificate FREEZES what it printed; an ID card reprint does not.
     *
     * The two sit side by side here because the difference is deliberate and
     * each half passes on its own: a service that resolved answers live would
     * satisfy the card assertion, and one that froze both would satisfy the
     * certificate assertion.
     */
    public function test_certificate_freezes_answers_while_a_card_reprint_follows_the_row(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventAsking($org, 'asal_kota', 'ukuran_jersey');

        $team = $this->squad($event, ['asal_kota' => 'Medan'], ['ukuran_jersey' => 'L']);
        $player = $team->players()->first();

        $template = $org->certificateTemplates()->create([
            'name' => 'Juara',
            'background_url' => 'https://cdn.test/bg.png',
            'orientation' => 'landscape',
            'fields' => [['key' => 'recipient_name', 'x' => 50, 'y' => 50, 'size' => 24]],
        ]);

        $certificates = app(CertificateService::class);

        $issued = $certificates->issueBatch(
            $event,
            $template,
            [['type' => 'player', 'id' => $player->id]],
            'Juara 1',
        )->first();

        $this->assertSame('L', $issued->field_values['custom:player:ukuran_jersey']);

        // The entrant's answer changes afterwards.
        $player->update(['custom_fields' => ['ukuran_jersey' => 'XXL']]);

        // The certificate still says what it said — it is a document quoted by
        // number, and its PDF is already in somebody's inbox.
        $this->assertSame('L', $certificates->values($issued->fresh())['custom:player:ukuran_jersey']);

        // The card does not: a reprint is supposed to carry today's data.
        $person = collect(app(IdCardService::class)->recipients($event->fresh()))
            ->firstWhere('id', $player->id);

        $this->assertSame('XXL', $person['values']['custom:player:ukuran_jersey']);
    }

    /**
     * A registration question may not displace a fixed field, whatever it is
     * keyed. Compared against the same card without the colliding question, so
     * a merge written the wrong way round cannot pass by accident.
     */
    public function test_a_custom_answer_never_overrides_a_fixed_field(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        // Keyed `name` deliberately — the namespacing is what keeps it apart
        // from the card's own `name`, and this is the test of that.
        $event = $this->eventOn($org, null, [
            'registration_form' => [
                'player_fields' => [
                    ['key' => 'name', 'label' => 'Nama panggilan', 'type' => 'short_text'],
                ],
            ],
        ]);

        $this->squad($event, [], ['name' => 'Fauzi kecil']);

        $person = collect(app(IdCardService::class)->recipients($event))
            ->firstWhere('type', 'player');

        // The nickname is placeable under its own key…
        $this->assertSame('Fauzi kecil', $person['values']['custom:player:name']);
        // …and the card's own `name` is untouched by it.
        $this->assertSame('Ahmad Fauzi', $person['name']);
        $this->assertArrayNotHasKey('name', array_diff_key($person['values'], ['name' => null]));
    }

    /**
     * The picker's list and the save-time check agree.
     *
     * Two readers of "which keys exist" is exactly the drift this feature could
     * introduce, so every key the catalogue offers for an event must be a key a
     * template of that org may carry.
     */
    public function test_every_offered_key_is_an_accepted_key(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventAsking($org, 'asal_kota', 'ukuran_jersey');

        $offered = array_column(TemplateFields::idCard($event), 'key');
        $accepted = TemplateFields::knownKeys($org, 'id_card');

        $this->assertSame([], array_values(array_diff($offered, $accepted)));
    }
}
