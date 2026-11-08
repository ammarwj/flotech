<?php

namespace Tests\Feature;

use App\Mail\IdCardsIssuedMail;
use App\Models\Event;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\IdCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The row behind a card, and the email that carries it.
 *
 * `QUEUE_CONNECTION=sync` in phpunit.xml means GenerateIdCardsJob and
 * SendIdCardsJob both run inside the request, so rows and `sent_at` are final
 * when it returns.
 *
 * Every case compares two states, because this feature's failure modes all pass
 * a single-state assertion: a reprint that mints a second number still produces
 * "a card exists", and a mailer that sends one message per card still produces
 * "mail was sent".
 */
class IdCardIssueAndSendTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function template(Organization $org): IdCardTemplate
    {
        $png = (new ImageManager(new GdDriver))->createImage(400, 260)->fill('#dddddd')->encode(new PngEncoder);
        Storage::disk('public')->put('id-cards/bg.png', (string) $png);

        return $org->idCardTemplates()->create([
            'name' => 'Kartu',
            'background_url' => Storage::disk('public')->url('id-cards/bg.png'),
            'width_mm' => 85.6,
            'height_mm' => 54,
            'fields' => [
                ['key' => 'name', 'x' => 50, 'y' => 40, 'size' => 5, 'align' => 'center'],
                ['key' => 'qr', 'x' => 70, 'y' => 55, 'w' => 22, 'h' => 22],
            ],
        ]);
    }

    /**
     * An approved team whose manager holds the given address — the only address
     * a player's card can be sent to, since `players` has no email column.
     *
     * @param  array<int, string>  $names
     * @return array{team: Team, players: array<int, Player>}
     */
    private function squad(Event $event, array $names, ?string $managerEmail = null): array
    {
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Team A',
            'status' => 'approved',
            'manager_user_id' => $managerEmail
                ? User::factory()->create(['email' => $managerEmail])->id
                : null,
        ]);

        return [
            'team' => $team,
            'players' => array_map(fn (string $n) => $team->players()->create(['full_name' => $n]), $names),
        ];
    }

    /**
     * @param  array<int, array{type: string, id: string}>  $recipients
     */
    private function print(User $user, Organization $org, Event $event, IdCardTemplate $template, array $recipients): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/id-cards", [
                'id_card_template_id' => $template->id,
                'recipients' => $recipients,
            ])
            ->assertStatus(202);
    }

    public function test_reprinting_keeps_the_card_number_rather_than_minting_a_second(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);
        $template = $this->template($org);

        ['players' => [$player]] = $this->squad($event, ['Ahmad Fauzi']);

        $this->print($user, $org, $event, $template, [['type' => 'player', 'id' => $player->id]]);

        $first = IdCard::sole();
        $firstNumber = $first->card_number;

        $this->print($user, $org, $event, $template, [['type' => 'player', 'id' => $player->id]]);

        // The count is the assertion, not the number. A second number would kill
        // the QR on the card already hanging around this player's neck, and an
        // `issue()` that called create() blindly still satisfies "a card for
        // Ahmad exists" — twice over.
        $this->assertSame(1, IdCard::count());
        $this->assertSame($firstNumber, IdCard::sole()->card_number);
    }

    public function test_a_reprint_refreshes_the_name_while_the_verification_page_keeps_its_number(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);
        $template = $this->template($org);

        ['players' => [$player]] = $this->squad($event, ['Ahmad Fauzi']);

        $this->print($user, $org, $event, $template, [['type' => 'player', 'id' => $player->id]]);
        $number = IdCard::sole()->card_number;

        // The public page must keep answering the number already printed, and it
        // must keep saying what the plastic says until a reprint happens.
        $this->getJson("/api/v1/public/id-cards/{$number}")
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'Ahmad Fauzi');

        $player->update(['full_name' => 'Ahmad Fauzi Rahmatullah']);

        $this->getJson("/api/v1/public/id-cards/{$number}")
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'Ahmad Fauzi');

        $this->print($user, $org, $event, $template, [['type' => 'player', 'id' => $player->id]]);

        // The pair: renaming alone must not move the snapshot (the card in
        // someone's hand still says the old name), but a reprint must, because
        // the card coming out of the printer now carries the new one. Asserting
        // either half alone passes a build that froze everything or one that
        // froze nothing.
        $this->getJson("/api/v1/public/id-cards/{$number}")
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'Ahmad Fauzi Rahmatullah')
            ->assertJsonPath('data.card_number', $number);
    }

    public function test_the_public_page_never_publishes_the_recipients_photo(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $card = $org->idCards()->create([
            'event_id' => $event->id,
            'card_number' => 'IDC-2026-11-0001',
            'recipient_type' => 'personnel',
            'recipient_id' => null,
            'recipient_name' => 'Ahmad Fauzi',
            'role_label' => 'Wasit',
            'issued_at' => now(),
        ]);

        // Narrow by construction, and asserted so: a card number looks guessable
        // enough that publishing the headshot would turn this endpoint into a way
        // to pull participants' photos, including minors in a youth tournament.
        $this->getJson("/api/v1/public/id-cards/{$card->card_number}")
            ->assertOk()
            ->assertJsonPath('data.role_label', 'Wasit')
            ->assertJsonMissingPath('data.photo_url')
            ->assertJsonMissingPath('data.recipient_email');

        $this->getJson('/api/v1/public/id-cards/IDC-2026-11-9999')->assertNotFound();
    }

    public function test_one_email_carries_a_whole_team_while_personnel_get_their_own(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);
        $template = $this->template($org);

        ['players' => $players] = $this->squad($event, ['Ahmad Fauzi', 'Budi Santoso'], 'manajer@example.test');

        $referee = $event->personnel()->create([
            'full_name' => 'Citra Dewi',
            'kind' => 'referee',
            'email' => 'citra@example.test',
        ]);

        $this->print($user, $org, $event, $template, [
            ['type' => 'player', 'id' => $players[0]->id],
            ['type' => 'player', 'id' => $players[1]->id],
            ['type' => 'personnel', 'id' => $referee->id],
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/id-cards/send")
            ->assertOk()
            ->assertJsonPath('data.emails', 2)
            ->assertJsonPath('data.cards', 3);

        // Three cards, two emails. This is the comparison the whole grouping
        // exists for: a per-card mailer sends three, and "mail was sent" is true
        // either way. The manager of a twenty-player squad is the person that
        // difference is for.
        Mail::assertSentCount(2);

        Mail::assertSent(IdCardsIssuedMail::class, fn (IdCardsIssuedMail $mail) => $mail->hasTo('manajer@example.test')
            && $mail->cards->count() === 2);

        Mail::assertSent(IdCardsIssuedMail::class, fn (IdCardsIssuedMail $mail) => $mail->hasTo('citra@example.test')
            && $mail->cards->count() === 1);

        $this->assertSame(3, IdCard::whereNotNull('sent_at')->count());
    }

    public function test_cards_with_no_address_are_reported_rather_than_dropped_silently(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);
        $template = $this->template($org);

        // One team with a manager account, one without. A team registered
        // without one legitimately has nowhere to send, and "2 kartu dikirim"
        // printed over three people reads as a bug rather than a fact.
        ['players' => [$withEmail]] = $this->squad($event, ['Ahmad Fauzi'], 'manajer@example.test');
        ['players' => [$without]] = $this->squad($event, ['Budi Santoso']);

        $this->print($user, $org, $event, $template, [
            ['type' => 'player', 'id' => $withEmail->id],
            ['type' => 'player', 'id' => $without->id],
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/id-cards/send")
            ->assertOk()
            ->assertJsonPath('data.cards', 1)
            ->assertJsonPath('data.skipped', 1);

        Mail::assertSentCount(1);

        // And the skipped one stays re-sendable: a card never marked as sent is
        // what lets the organizer fix the manager account and press send again.
        $this->assertNull(IdCard::where('recipient_id', $without->id)->sole()->sent_at);
        $this->assertNotNull(IdCard::where('recipient_id', $withEmail->id)->sole()->sent_at);
    }

    public function test_sending_is_refused_when_the_events_plan_has_no_id_card_generator(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $org = $this->orgFor($user);

        // Two events, one org, differing only by plan — the comparison the
        // plan-belongs-to-the-event invariant is tested with. Asserting the
        // refusal alone would pass a gate keyed on the organization.
        $allowed = $this->eventOn($org, $this->fullPlan());
        $denied = $this->eventOn($org, $this->planWith(['max_categories' => '-1']));

        foreach ([$allowed, $denied] as $i => $event) {
            $template = $this->template($org);
            ['players' => [$player]] = $this->squad($event, ['Ahmad Fauzi'], "manajer{$i}@example.test");

            if ($event->is($allowed)) {
                $this->print($user, $org, $event, $template, [['type' => 'player', 'id' => $player->id]]);
            } else {
                // Printing is gated too, so the denied event has no cards to
                // send — issue the row directly to prove the send gate itself
                // refuses rather than merely finding nothing.
                app(IdCardService::class)->issue($event, $template, [
                    'type' => 'player',
                    'id' => $player->id,
                    'name' => 'Ahmad Fauzi',
                    'email' => "manajer{$i}@example.test",
                ])->update(['png_key' => 'id-card-prints/x.png']);
            }
        }

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$allowed->id}/id-cards/send")
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$denied->id}/id-cards/send")
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'id_card_generator');

        Mail::assertSentCount(1);
    }

    public function test_the_print_png_survives_the_batch_prune_that_deletes_the_zip(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);

        ['players' => [$player]] = $this->squad($event, ['Ahmad Fauzi']);

        $this->print($user, $org, $event, $this->template($org), [['type' => 'player', 'id' => $player->id]]);

        $pngKey = (string) IdCard::sole()->png_key;
        $disk = Storage::disk('public');

        $this->assertTrue($disk->exists($pngKey));
        $this->assertNotEmpty($disk->files(IdCardService::BATCH_PREFIX));

        // `--hours=0` is clamped to 1, so reach back instead: everything on the
        // disk is older than the cutoff when the cutoff is in the future.
        $this->travel(2)->days();
        $this->artisan('id-cards:prune')->assertSuccessful();

        // The pair: the zip goes, the print stays. An `id-cards:prune` that
        // swept by extension across the whole disk passes "the zip is gone"
        // while killing the verification page for every card already handed
        // out — and MediaCleanupService would then be sweeping dead keys.
        $this->assertSame([], $disk->files(IdCardService::BATCH_PREFIX));
        $this->assertTrue($disk->exists($pngKey), 'the prune swept a permanent print');
    }
}
