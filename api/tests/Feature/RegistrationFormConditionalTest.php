<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Support\RegistrationForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Three things a registration field may carry: a consent box that records WHEN
 * it was ticked, guidance (text and an image) shown with the question, and a
 * `visible_if` rule that only asks it when another answer matches.
 *
 * Every uji here **compares** two states, because each feature's failure mode
 * passes a one-sided assert:
 *
 * - a consent whose timestamp came from the client still stores a timestamp;
 * - a hidden field that is still demanded still returns 422 for the people the
 *   condition includes;
 * - a hidden field that is still stored still looks right for the people who
 *   answered it.
 *
 * The race rail is used for the single-entrant half and the team rail for the
 * roster half, because the two have SEPARATE write paths through the same
 * schema — PublicRaceController::cleanAnswers() and
 * TeamRosterService::cleanAnswers() — and one passing proves nothing about the
 * other.
 */
class RegistrationFormConditionalTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Inside Early Bird below, and the instant every consent assertion
        // reads back. A wall clock would make "the server stamped it" a claim
        // the suite cannot check.
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    /** A fun run with one Early Bird window, and the given form schema. */
    private function raceEvent(Organization $org, array $form): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
            'registration_form' => $form,
        ]);

        $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-31T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        return $event->fresh();
    }

    private function category(Event $event, string $name = 'Umum'): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'quota' => null,
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => 0,
        ]);

        return $category;
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function enter(Organization $org, Event $event, RaceCategory $category, array $answers)
    {
        return $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => 'ammar@test.com',
                'custom_fields' => $answers,
            ]);
    }

    /** One field row, with whatever the test is about layered on. */
    private function field(string $key, string $label, array $extra = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'short_text',
            'required' => false,
            'options' => [],
            ...$extra,
        ];
    }

    // ---- consent ----

    /**
     * Invarian: waktu consent berasal dari jam SERVER, bukan dari payload.
     *
     * Dibandingkan dengan **membandingkan** dua pengiriman: satu yang mengirim
     * timestamp karangan tahun 1999, satu yang cuma mengirim "1". Keduanya
     * harus tersimpan sebagai jam server yang sama. Assert "tersimpan sebagai
     * timestamp" sendirian lolos penuh walau yang menentukan jamnya adalah
     * orang yang menyetujui.
     */
    public function test_consent_is_stamped_by_the_server_not_the_payload(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org, [
            'team_fields' => [
                ['key' => 'sehat', 'label' => 'Pernyataan sehat', 'type' => 'consent', 'required' => true, 'options' => []],
            ],
        ]);
        $category = $this->category($event);

        $forged = $this->enter($org, $event, $category, ['sehat' => '1999-01-01T00:00:00+07:00']);
        $plain = $this->enter($org, $event, $category, ['sehat' => '1']);

        $forged->assertCreated();
        $plain->assertCreated();

        $stamps = RaceEntry::pluck('custom_fields')->map(fn ($f) => $f['sehat'])->all();

        $this->assertCount(2, $stamps);

        foreach ($stamps as $stamp) {
            $this->assertSame(
                Carbon::parse('2026-01-05 03:00:00')->toIso8601String(),
                $stamp,
                'Consent harus distempel jam server, bukan nilai dari klien.',
            );
        }
    }

    /**
     * Kotak yang tidak dicentang bukan persetujuan, dan bedanya terlihat hanya
     * kalau yang dicentang juga diuji.
     *
     * "0" dan "false" adalah yang benar-benar dikirim klien untuk kotak kosong;
     * dibaca apa adanya keduanya non-kosong dan akan tercatat sebagai setuju.
     */
    public function test_an_unticked_consent_is_refused_while_a_ticked_one_passes(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org, [
            'team_fields' => [
                ['key' => 'sehat', 'label' => 'Pernyataan sehat', 'type' => 'consent', 'required' => true, 'options' => []],
            ],
        ]);
        $category = $this->category($event);

        // The error key holds a literal dot, so it is read off the decoded body
        // rather than with assertJsonPath, which would split it into a path.
        $refused = $this->enter($org, $event, $category, ['sehat' => '0'])->assertStatus(422);

        $this->assertSame(
            'Pernyataan sehat wajib disetujui.',
            $refused->json('errors')['custom_fields.sehat'] ?? null,
        );

        $this->enter($org, $event, $category, ['sehat' => 'false'])->assertStatus(422);

        // The half that binds it: the same field, ticked, goes through.
        $this->enter($org, $event, $category, ['sehat' => '1'])->assertCreated();

        $this->assertSame(1, RaceEntry::count());
    }

    /**
     * Yang dicetak ke manusia adalah "Ya · tanggal jam", bukan ISO mentah —
     * dan itu berlaku di jam venue, bukan UTC.
     *
     * Dibandingkan dengan field teks di baris sebelahnya: assert consent-nya
     * terformat sendirian akan lolos walau setiap jawaban ikut diproses.
     */
    public function test_consent_prints_its_time_while_text_prints_verbatim(): void
    {
        $form = RegistrationForm::fromArray([
            'team_fields' => [
                ['key' => 'sehat', 'label' => 'Sehat', 'type' => 'consent', 'required' => true, 'is_public' => true, 'options' => []],
                ['key' => 'kota', 'label' => 'Kota', 'type' => 'short_text', 'required' => false, 'is_public' => true, 'options' => []],
            ],
        ]);

        $published = $form->publicAnswers('team', [
            // 00:30 WIB on the 6th, i.e. 17:30 UTC on the 5th. Formatting
            // naively would print the previous day.
            'sehat' => '2026-01-05T17:30:00+00:00',
            'kota' => 'Yogyakarta',
        ]);

        $this->assertSame('Ya · 06/01/2026 00:30', $published[0]['value']);
        $this->assertSame('Yogyakarta', $published[1]['value']);
    }

    // ---- visible_if ----

    /**
     * Invarian: field tersembunyi tidak ditagih DAN tidak disimpan.
     *
     * Satu event, dua pengiriman. Pemicu cocok → field wajibnya ditagih, lalu
     * jawabannya tersimpan. Pemicu tidak cocok → tidak ditagih, dan jawaban
     * yang tetap dikirim tab lama **dibuang**. Satu arah sendirian lolos walau
     * arah lain tidak pernah jalan: menagih yang tak tampak mengunci formulir,
     * menyimpannya meninggalkan NIM pada orang yang memilih UMUM.
     */
    public function test_a_hidden_field_is_neither_demanded_nor_stored(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org, [
            'team_fields' => [
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true,
                    'options' => ['Mahasiswa UAD', 'UMUM']],
                ['key' => 'nim', 'label' => 'NIM', 'type' => 'short_text', 'required' => true,
                    'options' => [], 'visible_if' => ['field' => 'status', 'values' => ['Mahasiswa UAD']]],
            ],
        ]);
        $category = $this->category($event);

        // (a) Trigger matches and the conditional field is left empty: demanded.
        $demanded = $this->enter($org, $event, $category, ['status' => 'Mahasiswa UAD'])
            ->assertStatus(422);

        $this->assertSame(
            'NIM wajib diisi.',
            $demanded->json('errors')['custom_fields.nim'] ?? null,
        );

        // (b) Trigger does not match: not demanded, and the stale answer a
        // lingering tab still sends is dropped rather than stored.
        $this->enter($org, $event, $category, ['status' => 'UMUM', 'nim' => '2100018001'])
            ->assertCreated();

        $umum = RaceEntry::sole();
        $this->assertSame(['status' => 'UMUM'], $umum->custom_fields);
        $this->assertArrayNotHasKey('nim', $umum->custom_fields);

        // (c) And the answer IS kept for whoever the condition includes —
        // without this, "dropped" would also be satisfied by dropping it always.
        $this->enter($org, $event, $category, ['status' => 'Mahasiswa UAD', 'nim' => '2100018001'])
            ->assertCreated();

        $uad = RaceEntry::where('id', '!=', $umum->id)->sole();
        $this->assertSame('2100018001', $uad->custom_fields['nim']);
    }

    /**
     * Aturan yang sama berlaku di jalur tim, yang `cleanAnswers()`-nya adalah
     * method berbeda di kelas berbeda.
     *
     * Dibandingkan di dalam satu event: satu tim memicu, satu tidak.
     */
    public function test_the_team_rail_hides_the_same_field(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, [
            'sport_type' => 'football',
            'status' => 'open',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
            'registration_form' => [
                'team_fields' => [
                    ['key' => 'jenis', 'label' => 'Jenis', 'type' => 'select', 'required' => true,
                        'options' => ['Sekolah', 'Klub']],
                    ['key' => 'npsn', 'label' => 'NPSN', 'type' => 'short_text', 'required' => true,
                        'options' => [], 'visible_if' => ['field' => 'jenis', 'values' => ['Sekolah']]],
                ],
            ],
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'max_teams' => null, 'sort_order' => 0,
        ]);

        $post = fn (array $answers) => $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $category->id,
                'name' => 'Tim '.Str::random(5),
                'contact_name' => 'Ammar',
                'contact_phone' => '08123456789',
                'custom_fields' => $answers,
            ]);

        $post(['jenis' => 'Sekolah'])->assertStatus(422);
        $post(['jenis' => 'Klub', 'npsn' => '20403110'])->assertCreated();

        $team = $event->teams()->sole();
        $this->assertSame(['jenis' => 'Klub'], $team->custom_fields);
    }

    // ---- the schema itself ----

    /**
     * `visible_if` wajib menunjuk field di daftar yang sama dan di ATAS
     * barisnya. Itu yang menutup siklus tanpa aturan kedua.
     *
     * Dibandingkan dengan skema yang urutannya benar, dikirim dalam test yang
     * sama: assert 422 sendirian akan lolos walau setiap `visible_if` ditolak.
     */
    public function test_a_condition_must_name_a_field_above_it(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['sport_type' => 'fun_run']);

        $put = fn (array $fields) => $this->actingAs($owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", [
                'team_fields' => $fields,
                'player_fields' => [],
                'team_official_fields' => [],
                'team_documents' => [],
                'player_documents' => [],
                'team_official_documents' => [],
            ]);

        $trigger = $this->field('status', 'Status', ['type' => 'select', 'options' => ['A', 'B']]);
        $dependent = $this->field('nim', 'NIM', ['visible_if' => ['field' => 'status', 'values' => ['A']]]);

        // Below its trigger: refused.
        $put([$dependent, $trigger])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_fields.0.visible_if.field');

        // Pointing at itself: refused for the same reason, which is the case a
        // plain "is this key defined anywhere" check would let through.
        $put([$trigger, $this->field('nim', 'NIM', ['visible_if' => ['field' => 'nim', 'values' => ['A']]])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_fields.1.visible_if.field');

        // Above its trigger: accepted, and stored in the shape isVisible reads.
        $put([$trigger, $dependent])->assertOk();

        $stored = $event->fresh()->registration_form['team_fields'][1];
        $this->assertSame(['field' => 'status', 'values' => ['A']], $stored['visible_if']);
    }

    /**
     * Panduan field tersimpan dan terkirim; absennya tersimpan sebagai null,
     * bukan string kosong.
     *
     * Dibandingkan dalam satu baris: dua spelling untuk "tidak ada panduan"
     * berarti dua cabang render yang akan berselisih.
     */
    public function test_guidance_is_stored_and_absence_is_null(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['sport_type' => 'fun_run']);

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registration-form", [
                'team_fields' => [
                    $this->field('jersey', 'Ukuran Jersey', [
                        'type' => 'select',
                        'options' => ['S', 'M', 'L'],
                        'help_text' => 'Lihat panduan ukuran.',
                        'help_image_url' => 'https://cdn.example.com/jersey.webp',
                    ]),
                    // Sent as empty strings, which is what a browser form does
                    // for untouched inputs.
                    $this->field('kota', 'Kota', ['help_text' => '', 'help_image_url' => '']),
                ],
                'player_fields' => [],
                'team_official_fields' => [],
                'team_documents' => [],
                'player_documents' => [],
                'team_official_documents' => [],
            ])
            ->assertOk();

        [$jersey, $kota] = $event->fresh()->registration_form['team_fields'];

        $this->assertSame('Lihat panduan ukuran.', $jersey['help_text']);
        $this->assertSame('https://cdn.example.com/jersey.webp', $jersey['help_image_url']);
        $this->assertNull($kota['help_text']);
        $this->assertNull($kota['help_image_url']);
    }

    /**
     * Aturan tanpa nilai menormalkan ke null (selalu tampak), bukan ke
     * pertanyaan yang tak pernah cocok.
     *
     * Dibandingkan dengan aturan bernilai: assert "tersimpan null" sendirian
     * lolos walau setiap kondisi dibuang.
     */
    public function test_a_condition_with_no_values_normalizes_to_always_visible(): void
    {
        $form = RegistrationForm::fromArray([
            'team_fields' => [
                ['key' => 'a', 'label' => 'A', 'type' => 'short_text', 'required' => false, 'options' => []],
                ['key' => 'b', 'label' => 'B', 'type' => 'short_text', 'required' => false, 'options' => [],
                    'visible_if' => ['field' => 'a', 'values' => []]],
                ['key' => 'c', 'label' => 'C', 'type' => 'short_text', 'required' => false, 'options' => [],
                    'visible_if' => ['field' => 'a', 'values' => ['ya']]],
            ],
        ]);

        [, $b, $c] = $form->teamFields;

        $this->assertNull($b['visible_if']);
        $this->assertTrue(RegistrationForm::isVisible($b, []));

        // The pair: a real rule still hides.
        $this->assertSame(['field' => 'a', 'values' => ['ya']], $c['visible_if']);
        $this->assertFalse(RegistrationForm::isVisible($c, []));
        $this->assertTrue(RegistrationForm::isVisible($c, ['a' => 'ya']));
    }
}
