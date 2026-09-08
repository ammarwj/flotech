<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The organizer-defined registration form: extra fields and document slots on
 * the team and on each player.
 *
 * Almost every test here compares two states of the same event, because the
 * feature's failure modes all pass a one-sided assertion: a rejected row that
 * was written anyway still returns 422, documents swept by a too-wide prune
 * still leave the one being edited in place, and a schema the server never
 * enforces still stores what the client sent.
 */
class RegistrationFormTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function openEvent(array $form = []): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_category', 'value' => '10']);
        $plan->features()->create(['feature_key' => 'payment_gateway', 'value' => 'true']);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(),
            'registration_close' => Carbon::now()->addDays(10),
            'registration_form' => $form ?: null,
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'max_teams' => null, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    /** A schema with one required document on each side and one required player field. */
    private function fullSchema(): array
    {
        return [
            'team_fields' => [
                ['key' => 'alamat', 'label' => 'Alamat Tim', 'type' => 'short_text', 'required' => false, 'options' => []],
            ],
            'player_fields' => [
                ['key' => 'no_ktp', 'label' => 'No. KTP', 'type' => 'short_text', 'required' => true, 'options' => []],
            ],
            'team_documents' => [
                ['key' => 'surat_mandat', 'label' => 'Surat Mandat', 'required' => true, 'accept' => ['pdf']],
            ],
            'player_documents' => [
                ['key' => 'ktp', 'label' => 'KTP', 'required' => true, 'accept' => ['pdf', 'jpg', 'png']],
            ],
        ];
    }

    private function register(Event $event, User $manager, array $payload)
    {
        $org = $event->organization;

        return $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $payload + [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
            ]);
    }

    public function test_player_documents_are_separate_from_team_documents(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $manager = User::factory()->create();

        $teamId = $this->register($event, $manager, [
            'players' => [[
                'full_name' => 'Ammar',
                'custom_fields' => ['no_ktp' => '3201'],
                'documents' => [['file_url' => 'https://cdn/ktp.jpg', 'file_name' => 'ktp.jpg', 'document_type' => 'ktp']],
            ]],
            'documents' => [['file_url' => 'https://cdn/mandat.pdf', 'file_name' => 'mandat.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated()->json('data.team.id');

        $team = \App\Models\Team::find($teamId);
        $player = $team->players()->first();

        // Compared both ways round: asserting only that each document was stored
        // proves nothing about which scope it landed in, and a player_id that was
        // never written would leave both lists holding both rows.
        $this->assertSame(['surat_mandat'], $team->documents()->pluck('document_type')->all());
        $this->assertSame(['ktp'], $player->documents()->pluck('document_type')->all());
        $this->assertSame(2, $team->allDocuments()->count());
    }

    public function test_typed_player_row_must_be_complete(): void
    {
        $event = $this->openEvent($this->fullSchema());

        // Complete row → in.
        $this->register($event, User::factory()->create(), [
            'players' => [[
                'full_name' => 'Lengkap',
                'custom_fields' => ['no_ktp' => '3201'],
                'documents' => [['file_url' => 'https://cdn/a.jpg', 'file_name' => 'a.jpg', 'document_type' => 'ktp']],
            ]],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();

        // Same event, a name typed without its papers → out.
        $this->register($event, User::factory()->create(), [
            'name' => 'Kurang',
            'players' => [['full_name' => 'Ammar']],
            'documents' => [['file_url' => 'https://cdn/m2.pdf', 'file_name' => 'm2.pdf', 'document_type' => 'surat_mandat']],
        ])->assertStatus(422)->assertJsonValidationErrors(['players.0.documents', 'players.0.custom_fields.no_ktp']);

        // The count, not the status code: a row written and then rejected still
        // returns 422, and the name the organizer refused would be sitting in the
        // roster with nobody looking at it.
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseMissing('players', ['full_name' => 'Ammar']);
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_empty_roster_is_still_accepted_when_player_documents_are_required(): void
    {
        $event = $this->openEvent($this->fullSchema());

        // No players sent = no rows to check. "Register now, fill the squad in
        // later" survives as a consequence of the loop shape, not an exception.
        $this->register($event, User::factory()->create(), [
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();

        $this->assertDatabaseCount('players', 0);
    }

    public function test_resaving_an_unchanged_team_is_not_rejected(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $manager = User::factory()->create();

        $teamId = $this->register($event, $manager, [
            'players' => [[
                'full_name' => 'Ammar',
                'custom_fields' => ['no_ktp' => '3201'],
                'documents' => [['file_url' => 'https://cdn/ktp.jpg', 'file_name' => 'ktp.jpg', 'document_type' => 'ktp']],
            ]],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated()->json('data.team.id');

        $team = \App\Models\Team::find($teamId);
        $player = $team->players()->first();

        // Exactly what the edit form sends back: stored rows, by id, unchanged.
        // Asserting completeness before syncing the documents would 422 here and
        // lock the participant out of their own form.
        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'players' => [[
                    'id' => $player->id,
                    'full_name' => 'Ammar',
                    'custom_fields' => ['no_ktp' => '3201'],
                    'documents' => [[
                        'id' => $player->documents()->first()->id,
                        'file_url' => 'https://cdn/ktp.jpg',
                        'file_name' => 'ktp.jpg',
                        'document_type' => 'ktp',
                    ]],
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseCount('registration_documents', 2);
    }

    public function test_rule_applies_on_the_organizer_route_too(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $org = $event->organization;

        $teamId = $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Tim Offline',
                'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
            ])
            ->assertCreated()
            ->json('data.id');

        // The same gate on the approved team: four write paths, one rule, so the
        // organizer route is not a way around what the public form enforces.
        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Tim Offline',
                'players' => [['full_name' => 'Ammar']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['players.0.documents']);

        $this->assertDatabaseCount('players', 0);
    }

    public function test_document_type_can_be_corrected(): void
    {
        $event = $this->openEvent([
            'team_fields' => [], 'player_fields' => [], 'player_documents' => [],
            'team_documents' => [
                ['key' => 'surat_mandat', 'label' => 'Surat Mandat', 'required' => false, 'accept' => ['pdf']],
                ['key' => 'akta', 'label' => 'Akta Klub', 'required' => false, 'accept' => ['pdf']],
            ],
        ]);
        $manager = User::factory()->create();

        $teamId = $this->register($event, $manager, [
            'documents' => [['file_url' => 'https://cdn/x.pdf', 'file_name' => 'x.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated()->json('data.team.id');

        $doc = \App\Models\Team::find($teamId)->documents()->first();
        $this->assertSame('surat_mandat', $doc->document_type);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'documents' => [[
                    'id' => $doc->id,
                    'file_url' => 'https://cdn/x.pdf',
                    'file_name' => 'x.pdf',
                    'document_type' => 'akta',
                ]],
            ])
            ->assertOk();

        // Before/after on the same row. Rows carrying an id used to be skipped
        // entirely, which made document_type write-once — and the misfiled row
        // still existed afterwards, so counting documents would not catch it.
        $this->assertSame('akta', $doc->fresh()->document_type);
        $this->assertDatabaseCount('registration_documents', 1);
    }

    public function test_syncing_one_players_documents_leaves_the_others_alone(): void
    {
        $event = $this->openEvent([
            'team_fields' => [], 'player_fields' => [],
            'team_documents' => [['key' => 'surat_mandat', 'label' => 'Surat Mandat', 'required' => false, 'accept' => ['pdf']]],
            'player_documents' => [['key' => 'ktp', 'label' => 'KTP', 'required' => false, 'accept' => ['jpg']]],
        ]);
        $manager = User::factory()->create();

        $teamId = $this->register($event, $manager, [
            'players' => [
                ['full_name' => 'A', 'documents' => [['file_url' => 'https://cdn/a.jpg', 'file_name' => 'a.jpg', 'document_type' => 'ktp']]],
                ['full_name' => 'B', 'documents' => [['file_url' => 'https://cdn/b.jpg', 'file_name' => 'b.jpg', 'document_type' => 'ktp']]],
            ],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated()->json('data.team.id');

        $team = \App\Models\Team::find($teamId);
        $a = $team->players()->where('full_name', 'A')->first();
        $b = $team->players()->where('full_name', 'B')->first();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'players' => [
                    ['id' => $a->id, 'full_name' => 'A', 'documents' => [['file_url' => 'https://cdn/a2.jpg', 'file_name' => 'a2.jpg', 'document_type' => 'ktp']]],
                    ['id' => $b->id, 'full_name' => 'B', 'documents' => [[
                        'id' => $b->documents()->first()->id,
                        'file_url' => 'https://cdn/b.jpg', 'file_name' => 'b.jpg', 'document_type' => 'ktp',
                    ]]],
                ],
            ])
            ->assertOk();

        // Asserting only that A's document changed would pass with a prune scoped
        // to the whole team — which would have deleted the mandate letter and B's
        // KTP, and dispatched a purge for their files.
        $this->assertSame('https://cdn/a2.jpg', $a->documents()->first()->file_url);
        $this->assertSame('https://cdn/b.jpg', $b->documents()->first()->file_url);
        $this->assertSame(['surat_mandat'], $team->documents()->pluck('document_type')->all());
    }

    public function test_event_without_a_schema_refuses_documents(): void
    {
        $withSchema = $this->openEvent([
            'team_fields' => [], 'player_fields' => [], 'player_documents' => [],
            'team_documents' => [['key' => 'surat_mandat', 'label' => 'Surat Mandat', 'required' => false, 'accept' => ['pdf']]],
        ]);
        $without = $this->openEvent();

        $payload = ['documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']]];

        // Same payload, two events. The server half of "no documents defined ⇒
        // nothing to upload": hiding the block in the UI does not stop an older
        // client from posting stray files.
        $this->register($withSchema, User::factory()->create(), $payload)->assertCreated();
        $this->register($without, User::factory()->create(), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0.document_type']);

        $this->assertDatabaseCount('registration_documents', 1);
    }

    public function test_document_extension_must_match_what_the_slot_accepts(): void
    {
        $event = $this->openEvent([
            'team_fields' => [], 'player_fields' => [], 'player_documents' => [],
            'team_documents' => [['key' => 'surat_mandat', 'label' => 'Surat Mandat', 'required' => false, 'accept' => ['pdf']]],
        ]);

        $this->register($event, User::factory()->create(), [
            'documents' => [['file_url' => 'https://cdn/m.jpg', 'file_name' => 'm.jpg', 'document_type' => 'surat_mandat']],
        ])->assertStatus(422)->assertJsonValidationErrors(['documents.0.document_type']);

        $this->register($event, User::factory()->create(), [
            'name' => 'Lain',
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();
    }

    public function test_key_in_use_cannot_be_renamed_but_label_can(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $org = $event->organization;

        $this->register($event, User::factory()->create(), [
            'custom_fields' => ['alamat' => 'Jl. Merdeka 1'],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();

        $renamed = $this->fullSchema();
        $renamed['team_fields'][0]['key'] = 'alamat_tim';

        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", $renamed)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_fields']);

        // The surviving answer, not just the status: SyncPlanFeaturesRequest's
        // lesson — a 422 raised after the write would look identical here.
        $this->assertSame(['alamat' => 'Jl. Merdeka 1'], $event->teams()->first()->custom_fields);
        $this->assertSame('alamat', $event->fresh()->registration_form['team_fields'][0]['key']);

        // Dropping a document slot that has uploads is the same refusal.
        $withoutDoc = $this->fullSchema();
        $withoutDoc['team_documents'] = [];

        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", $withoutDoc)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_documents']);

        // Relabelling is free, and takes effect immediately.
        $relabelled = $this->fullSchema();
        $relabelled['team_fields'][0]['label'] = 'Alamat Sekretariat';

        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", $relabelled)
            ->assertOk()
            ->assertJsonPath('data.team_fields.0.label', 'Alamat Sekretariat')
            ->assertJsonPath('data.team_fields.0.key', 'alamat');
    }

    public function test_unused_key_may_be_dropped_freely(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $org = $event->organization;

        // No teams yet: the organizer is still designing the form, which is when
        // fields are dropped most often. Checked against stored answers, not
        // against the previous schema, so this stays allowed.
        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", [
                'team_fields' => [], 'player_fields' => [], 'team_documents' => [], 'player_documents' => [],
            ])
            ->assertOk();

        $this->assertSame([], $event->fresh()->registration_form['team_fields']);
    }

    public function test_select_must_have_options_and_answers_must_match_them(): void
    {
        $org = $this->openEvent()->organization;
        $event = $org->events()->first();

        $schema = [
            'team_fields' => [['key' => 'grup', 'label' => 'Grup', 'type' => 'select', 'required' => true, 'options' => []]],
            'player_fields' => [], 'team_documents' => [], 'player_documents' => [],
        ];

        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", $schema)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_fields.0.options']);

        $schema['team_fields'][0]['options'] = ['A', 'B'];

        $this->actingAs($org->owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", $schema)
            ->assertOk();

        $event->refresh();

        $this->register($event, User::factory()->create(), ['custom_fields' => ['grup' => 'C']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.grup']);

        $this->register($event, User::factory()->create(), ['custom_fields' => ['grup' => 'A']])
            ->assertCreated();
    }

    public function test_duplicate_keys_within_a_list_are_rejected_but_across_lists_are_fine(): void
    {
        $org = $this->openEvent()->organization;
        $event = $org->events()->first();
        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form";

        $field = fn (string $key) => ['key' => $key, 'label' => 'X', 'type' => 'short_text', 'required' => false, 'options' => []];

        $this->actingAs($org->owner, 'api')->putJson($url, [
            'team_fields' => [$field('alamat'), $field('alamat')],
            'player_fields' => [], 'team_documents' => [], 'player_documents' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['team_fields.1.key']);

        // A team address and a player address are different questions; scoping
        // uniqueness to the list is what lets the organizer ask both.
        $this->actingAs($org->owner, 'api')->putJson($url, [
            'team_fields' => [$field('alamat')],
            'player_fields' => [$field('alamat')],
            'team_documents' => [], 'player_documents' => [],
        ])->assertOk();
    }

    public function test_upload_converts_images_to_webp_and_leaves_pdf_alone(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $webp = $this->actingAs($user, 'api')
            ->postJson('/api/v1/uploads/document', ['file' => UploadedFile::fake()->image('ktp.png', 400, 400)])
            ->assertOk()
            ->json('data');

        $pdf = $this->actingAs($user, 'api')
            ->postJson('/api/v1/uploads/document', ['file' => UploadedFile::fake()->create('mandat.pdf', 20, 'application/pdf')])
            ->assertOk()
            ->json('data');

        // Compared against each other: asserting only the PNG would pass if the
        // handler re-encoded everything, and asserting only the PDF would pass if
        // it re-encoded nothing.
        $this->assertStringEndsWith('.webp', $webp['key']);
        $this->assertStringEndsWith('.webp', $webp['file_name']);
        $this->assertStringEndsWith('.pdf', $pdf['key']);
        $this->assertStringEndsWith('.pdf', $pdf['file_name']);

        Storage::disk('public')->assertExists($webp['key']);
        Storage::disk('public')->assertExists($pdf['key']);
    }

    public function test_upload_rejects_oversized_and_unsupported_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/uploads/document', ['file' => UploadedFile::fake()->create('big.pdf', 6144, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/uploads/document', ['file' => UploadedFile::fake()->create('evil.exe', 10)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_public_resource_carries_the_schema_but_no_answers(): void
    {
        $event = $this->openEvent($this->fullSchema());
        $org = $event->organization;

        $this->register($event, User::factory()->create(), [
            'custom_fields' => ['alamat' => 'Jl. Rahasia 9'],
            'players' => [[
                'full_name' => 'Ammar',
                'custom_fields' => ['no_ktp' => '3201999'],
                'documents' => [['file_url' => 'https://cdn/k.jpg', 'file_name' => 'k.jpg', 'document_type' => 'ktp']],
            ]],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();

        $event->teams()->update(['status' => 'approved']);

        // Rendered straight from the resource rather than through the endpoint:
        // PublicEventController::show orders the roster with Postgres-only regex
        // SQL that SQLite cannot parse. The filtering under test lives here.
        $payload = (new \App\Http\Resources\PublicEventResource(
            $event->fresh()->load(['organization', 'categories', 'teams.players', 'teams.officials'])
        ))->toArray(request());

        $body = json_encode($payload);

        $this->assertSame('ktp', $payload['registration_form']['player_documents'][0]['key']);

        // The shape is public because the public form renders itself from it; the
        // answers are exactly the class of field this resource already filters out
        // of the roster. Searched in the raw body so a new nesting cannot smuggle
        // them past a path assertion.
        $this->assertStringNotContainsString('Jl. Rahasia 9', $body);
        $this->assertStringNotContainsString('3201999', $body);
        $this->assertStringNotContainsString('custom_fields', $body);
    }

    public function test_team_fields_become_export_columns(): void
    {
        $event = $this->openEvent($this->fullSchema());

        $this->register($event, User::factory()->create(), [
            'custom_fields' => ['alamat' => 'Jl. Merdeka 1'],
            'documents' => [['file_url' => 'https://cdn/m.pdf', 'file_name' => 'm.pdf', 'document_type' => 'surat_mandat']],
        ])->assertCreated();

        $export = new \App\Exports\RegistrationsExport($event->fresh());
        $headings = $export->headings();
        $row = $export->rows()[0];

        // The label heads the column and the answer fills it — both appended
        // after the fixed columns, so an organizer diffing exports does not find
        // the old ones moved.
        $this->assertSame('Alamat Tim', end($headings));
        $this->assertSame('Jl. Merdeka 1', end($row));
    }
}
