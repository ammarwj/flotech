<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;
use ZipArchive;

/**
 * Printing a batch: who gets in the zip, who does not, and who may read it.
 *
 * `QUEUE_CONNECTION=sync` in phpunit.xml means GenerateIdCardsJob runs inside
 * the POST, so the batch entry is already final when the request returns and
 * the download can be asked for in the same test.
 *
 * Every case compares two states. A generator that prints the whole event
 * satisfies "the selected two are in there"; the absent third is what makes the
 * assertion mean something.
 */
class IdCardGenerateTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No R2 credentials under test, so IdCardService writes the zip to the
        // `public` disk and the download endpoint reads it back from there.
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
                ['key' => 'name', 'x' => 50, 'y' => 60, 'size' => 5, 'align' => 'center'],
            ],
        ]);
    }

    /**
     * An approved team with the named players, which is what
     * IdCardService::recipients() walks.
     *
     * @param  array<int, string>  $names
     * @return array<int, Player>
     */
    private function squad(Event $event, array $names): array
    {
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Team A',
            'status' => 'approved',
        ]);

        return array_map(fn (string $name) => $team->players()->create(['full_name' => $name]), $names);
    }

    /**
     * @param  array<int, array{type: string, id: string}>  $recipients
     */
    private function generate(User $user, Organization $org, Event $event, IdCardTemplate $template, array $recipients): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/id-cards", [
                'id_card_template_id' => $template->id,
                'recipients' => $recipients,
            ]);
    }

    /**
     * The entry names inside the zip a finished batch produced.
     *
     * Read through the download endpoint rather than off the disk, so the test
     * exercises the same disk branch the browser would.
     *
     * @return array<int, string>
     */
    private function entriesOf(User $user, Organization $org, string $batchId): array
    {
        $response = $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/id-card-batches/{$batchId}/download")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'idcardtest').'.zip';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($path);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $names;
    }

    public function test_the_zip_holds_one_entry_per_selected_recipient_and_none_for_the_rest(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);

        [$picked, $alsoPicked, $skipped] = $this->squad($event, ['Ahmad Fauzi', 'Budi Santoso', 'Citra Dewi']);

        $batch = $this->generate($user, $org, $event, $this->template($org), [
            ['type' => 'player', 'id' => $picked->id],
            ['type' => 'player', 'id' => $alsoPicked->id],
        ])->assertStatus(202)->json('data.batch_id');

        $names = $this->entriesOf($user, $org, $batch);

        // Three players exist, two were asked for. The absent third is the whole
        // point: a generator that ignored the selection and printed the event
        // passes any assertion about the two that were chosen.
        $this->assertCount(2, $names);
        $this->assertStringContainsString('ahmad-fauzi', implode('|', $names));
        $this->assertStringContainsString('budi-santoso', implode('|', $names));
        $this->assertStringNotContainsString('citra-dewi', implode('|', $names));
    }

    public function test_two_recipients_with_the_same_name_get_two_entries(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);

        [$one, $two] = $this->squad($event, ['Ahmad Fauzi', 'Ahmad Fauzi']);

        $batch = $this->generate($user, $org, $event, $this->template($org), [
            ['type' => 'player', 'id' => $one->id],
            ['type' => 'player', 'id' => $two->id],
        ])->assertStatus(202)->json('data.batch_id');

        $names = $this->entriesOf($user, $org, $batch);

        // Both halves: two entries, and two *different* entry names. A naive
        // `slug(name).png` writes the second card over the first and leaves an
        // archive of one — which "the names are distinct" alone would not catch,
        // and "there are two files" alone would not either if the collision
        // resolved to the same key.
        $this->assertCount(2, $names);
        $this->assertCount(2, array_unique($names));
    }

    public function test_generating_is_refused_on_an_event_whose_plan_lacks_the_feature(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        // One organization, one template, two events. Only the plan differs, so
        // the pair of status codes cannot be explained by anything else — and
        // both halves are needed: a gate stuck open passes the first, a gate
        // stuck shut passes the second.
        $allowed = $this->eventOn($org);
        $refused = $this->eventOn($org, $this->planWith(['certificate_generator' => 'true']));

        $template = $this->template($org);
        [$here] = $this->squad($allowed, ['Ahmad Fauzi']);
        [$there] = $this->squad($refused, ['Budi Santoso']);

        $this->generate($user, $org, $allowed, $template, [['type' => 'player', 'id' => $here->id]])
            ->assertStatus(202);

        $this->generate($user, $org, $refused, $template, [['type' => 'player', 'id' => $there->id]])
            ->assertForbidden()
            ->assertJsonPath('errors.feature', 'id_card_generator');
    }

    public function test_another_organization_cannot_read_a_batch(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner, 'Punya A');
        $event = $this->eventOn($org);
        [$player] = $this->squad($event, ['Ahmad Fauzi']);

        $batch = $this->generate($owner, $org, $event, $this->template($org), [
            ['type' => 'player', 'id' => $player->id],
        ])->assertStatus(202)->json('data.batch_id');

        $stranger = User::factory()->create();
        $otherOrg = $this->orgFor($stranger, 'Punya B');
        $this->eventOn($otherOrg);

        // The batch id is a UUID in a shared cache, so without the ownership
        // check holding one would be enough to read another organizer's roster
        // photos. 404 rather than 403 — whether an id exists is itself not
        // something a stranger should learn.
        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/organizations/{$otherOrg->id}/id-card-batches/{$batch}")
            ->assertNotFound();

        $this->actingAs($stranger, 'api')
            ->get("/api/v1/organizations/{$otherOrg->id}/id-card-batches/{$batch}/download")
            ->assertNotFound();

        // The same two reads succeed for the organization that owns it, which is
        // what proves the refusal was about ownership and not about the batch
        // having expired or never existed.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/id-card-batches/{$batch}")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->actingAs($owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/id-card-batches/{$batch}/download")
            ->assertOk();
    }
}
