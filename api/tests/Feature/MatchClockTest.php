<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Babak & jam pertandingan.
 *
 * Yang tersimpan adalah *anchor*, bukan menit — jadi hampir tidak ada di sini
 * yang bisa dibuktikan dengan satu assert. "Jamnya berjalan" tetap hijau di
 * mesin yang menyalakan jam untuk semua laga; "jamnya berhenti" tetap hijau
 * walau anchornya tertinggal dan baru meledak di pembacaan berikutnya. Karena
 * itu tiap uji di sini **membandingkan** dua keadaan yang harus keluar berbeda:
 * laga berjalan vs laga terjeda, cabang goal vs cabang set, pintu organizer vs
 * pintu petugas.
 *
 * Katalognya katalog sungguhan (`SportSeeder` lewat `TestCase::$seeder`), bukan
 * cabang sintetis: `period_config` basket ada di sana, dan cabang buatan sendiri
 * tanpa key itu akan melewatkan tepat perbedaan yang diuji.
 */
class MatchClockTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private const CLOCK_URL_ORG = '/api/v1/organizations/%s/matches/%s/clock';

    private const CLOCK_URL_STAFF = '/api/v1/officiating/events/%s/matches/%s/clock';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures ----

    /**
     * Satu laga siap ditekan tombolnya, di cabang & bentuk kategori yang diminta.
     *
     * @param  array<string, mixed>  $categoryAttrs
     * @param  array<string, mixed>  $eventAttrs
     * @return array{0: Organization, 1: Event, 2: GameMatch}
     */
    private function scene(
        User $owner,
        string $sport = 'football',
        array $categoryAttrs = [],
        array $eventAttrs = [],
    ): array {
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['sport_type' => $sport, ...$eventAttrs]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum-'.uniqid(),
            'tournament_format' => 'league',
            'participant_type' => 'team',
            'registration_fee' => 0,
            'sort_order' => 0,
            ...$categoryAttrs,
        ]);

        $match = $event->matches()->create([
            'category_id' => $category->id,
            'home_team_id' => $this->teamOn($event, $category, 'Home')->id,
            'away_team_id' => $this->teamOn($event, $category, 'Away')->id,
            'round' => 1,
            'order' => 0,
            'status' => 'scheduled',
        ]);

        return [$org, $event, $match];
    }

    private function teamOn(Event $event, EventCategory $category, string $name): Team
    {
        return $event->teams()->create([
            'category_id' => $category->id,
            'name' => $name.'-'.uniqid(),
            'status' => 'approved',
        ]);
    }

    /**
     * Satu kru di sebuah event, password-nya sudah dirotasi supaya 403 di sini
     * tidak pernah berarti `password.rotated`.
     */
    private function crew(Event $event, string $kind, string $email = 'crew@example.test'): User
    {
        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Petugas', 'kind' => $kind, 'email' => $email],
        ]);

        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user->fresh();
    }

    /** URL papan skor publik — endpoint yang sebenarnya dipolling tiap 10 detik. */
    private function publicUrl(GameMatch $match): string
    {
        $event = $match->event->loadMissing('organization');

        return "/api/v1/public/events/{$event->organization->slug}/{$event->slug}/matches/{$match->id}";
    }

    /** @param array<string, mixed> $body */
    private function act(User $as, Organization $org, GameMatch $match, array $body)
    {
        return $this->actingAs($as, 'api')
            ->patchJson(sprintf(self::CLOCK_URL_ORG, $org->id, $match->id), $body);
    }

    // ---- 1. berjalan vs terjeda ----

    public function test_a_running_clock_advances_with_the_wall_while_a_paused_one_does_not(): void
    {
        $owner = User::factory()->create();
        [$org, , $running] = $this->scene($owner);
        [, , $paused] = $this->scene($owner);

        Carbon::setTestNow('2026-09-25 08:00:00');

        $this->act($owner, $running->event->organization, $running, ['action' => 'start'])->assertOk();

        // Laga kedua dijalankan lalu langsung dijeda: detiknya terkumpul 0, dan
        // anchornya dilepas. Bedanya dengan laga pertama cuma satu tap.
        $pausedOrg = $paused->event->organization;
        $this->act($owner, $pausedOrg, $paused, ['action' => 'start'])->assertOk();
        $this->act($owner, $pausedOrg, $paused, ['action' => 'pause'])->assertOk();

        Carbon::setTestNow('2026-09-25 08:01:30');

        $runningSeconds = $this->act($owner, $org, $running, ['action' => 'pause'])
            ->assertOk()
            ->json('data.clock.elapsed_seconds');

        $pausedSeconds = $this->act($owner, $pausedOrg, $paused, ['action' => 'pause'])
            ->assertOk()
            ->json('data.clock.elapsed_seconds');

        $this->assertSame(90, $runningSeconds, 'jam berjalan ikut dinding');
        $this->assertSame(0, $pausedSeconds, 'jam yang terjeda tidak bergerak sama sekali');
    }

    public function test_starting_the_clock_lifts_a_scheduled_fixture_to_ongoing(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner);

        $this->assertSame('scheduled', $match->status);

        $this->act($owner, $org, $match, ['action' => 'start'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing')
            ->assertJsonPath('data.clock.period', 1)
            ->assertJsonPath('data.clock.running', true);
    }

    // ---- 2. status meninggalkan `ongoing` membekukan jam ----

    public function test_finishing_a_match_freezes_the_clock_instead_of_leaving_it_anchored(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner);

        Carbon::setTestNow('2026-09-25 08:00:00');
        $this->act($owner, $org, $match, ['action' => 'start'])->assertOk();

        Carbon::setTestNow('2026-09-25 08:01:07');
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
            ])
            ->assertOk();

        $frozen = $match->fresh();

        // Detiknya dilipat, bukan dibuang: laga yang berhenti di 67:14 memang
        // berhenti di sana, dan itu bagian dari hasilnya.
        $this->assertSame(67, (int) $frozen->clock_elapsed_seconds);
        $this->assertNull($frozen->clock_started_at, 'anchornya dilepas, bukan cuma diabaikan');

        // Lima menit kemudian, dibaca lewat endpoint yang benar-benar dipolling
        // papan skor: kalau anchornya tertinggal, angka ini ikut naik.
        Carbon::setTestNow('2026-09-25 08:06:07');

        $after = $this->getJson($this->publicUrl($match))
            ->assertOk()
            ->json('data.match.clock.elapsed_seconds');

        $this->assertSame(67, $after, 'jam laga selesai tidak boleh terus berdetak');
    }

    // ---- 3. cabang set vs cabang goal ----

    public function test_a_set_sport_has_no_clock_while_a_running_score_sport_does(): void
    {
        $owner = User::factory()->create();
        [$goalOrg, , $goal] = $this->scene($owner, 'football');
        [$setOrg, , $set] = $this->scene($owner, 'volleyball');

        $this->act($owner, $goalOrg, $goal, ['action' => 'start'])
            ->assertOk()
            ->assertJsonPath('data.clock.label', 'Babak')
            ->assertJsonPath('data.clock.periods', 2)
            ->assertJsonPath('data.clock.period_minutes', 45);

        // Voli beregu dan berskor set: papan skornya sudah menampilkan set, dan
        // "Babak 1" di atasnya salah.
        $this->act($owner, $setOrg, $set, ['action' => 'start'])->assertStatus(422);

        // Dibandingkan dengan papan skor sepak bola lewat endpoint & path yang
        // sama: assert null sendirian juga hijau kalau path-nya salah ketik.
        $this->assertNull(
            $this->getJson($this->publicUrl($set))->assertOk()->json('data.match.clock'),
            'papan skor voli tidak boleh menerima blok jam sama sekali',
        );
        $this->assertNotNull(
            $this->getJson($this->publicUrl($goal))->assertOk()->json('data.match.clock'),
        );
    }

    public function test_basketball_carries_its_own_quarters_from_the_catalogue(): void
    {
        $owner = User::factory()->create();
        [$footballOrg, , $football] = $this->scene($owner, 'football');
        [$basketOrg, , $basket] = $this->scene($owner, 'basketball');

        $babak = $this->act($owner, $footballOrg, $football, ['action' => 'start'])
            ->assertOk()
            ->json('data.clock');

        $kuarter = $this->act($owner, $basketOrg, $basket, ['action' => 'start'])
            ->assertOk()
            ->json('data.clock');

        // Dibandingkan, bukan di-assert sendiri: nilai basket yang benar juga
        // keluar dari DEFAULTS yang kebetulan dibaca untuk semua cabang.
        $this->assertSame(['Babak', 2, 45], [$babak['label'], $babak['periods'], $babak['period_minutes']]);
        $this->assertSame(['Kuarter', 4, 10], [$kuarter['label'], $kuarter['periods'], $kuarter['period_minutes']]);
    }

    public function test_an_event_override_beats_the_sport_default_while_a_sibling_keeps_it(): void
    {
        $owner = User::factory()->create();
        [$plainOrg, , $plain] = $this->scene($owner, 'football');
        [$customOrg, , $custom] = $this->scene($owner, 'football', [], [
            'rules_config' => ['clock' => ['period_minutes' => 25, 'label' => null]],
        ]);

        $default = $this->act($owner, $plainOrg, $plain, ['action' => 'start'])->assertOk()->json('data.clock');
        $override = $this->act($owner, $customOrg, $custom, ['action' => 'start'])->assertOk()->json('data.clock');

        $this->assertSame(45, $default['period_minutes']);
        $this->assertSame(25, $override['period_minutes']);

        // `label: null` berarti "ikut cabang", bukan "kosongkan" — clean() yang
        // membuangnya sebelum merge.
        $this->assertSame('Babak', $override['label']);
    }

    // ---- 4. kategori ber-partai ----

    public function test_a_rubber_tie_is_refused_even_though_its_sport_would_otherwise_qualify(): void
    {
        $owner = User::factory()->create();

        // Sepak bola untuk keduanya supaya cabangnya tidak bisa menjelaskan
        // bedanya; yang berbeda cuma `rubber_format` kategorinya. Badminton
        // sudah ditolak lebih dulu karena berskor set.
        [$plainOrg, , $plain] = $this->scene($owner, 'badminton', ['participant_type' => 'single']);
        [$tieOrg, , $tie] = $this->scene($owner, 'badminton', [
            'rubber_format' => [['label' => 'Tunggal Putra', 'type' => 'single']],
        ]);

        $this->act($owner, $plainOrg, $plain, ['action' => 'start'])->assertStatus(422);
        $this->act($owner, $tieOrg, $tie, ['action' => 'start'])->assertStatus(422);

        [$goalOrg, , $goal] = $this->scene($owner, 'football');
        $this->act($owner, $goalOrg, $goal, ['action' => 'start'])->assertOk();
    }

    // ---- 5. advance ----

    public function test_the_next_period_restarts_the_clock_and_cannot_pass_the_last_one(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner, 'football');

        Carbon::setTestNow('2026-09-25 08:00:00');
        $this->act($owner, $org, $match, ['action' => 'start'])->assertOk();

        Carbon::setTestNow('2026-09-25 08:45:00');
        $first = $this->act($owner, $org, $match, ['action' => 'pause'])->assertOk()->json('data.clock');

        $second = $this->act($owner, $org, $match, ['action' => 'advance'])->assertOk()->json('data.clock');

        $this->assertSame([1, 2700], [$first['period'], $first['elapsed_seconds']]);
        $this->assertSame([2, 0, true], [$second['period'], $second['elapsed_seconds'], $second['running']]);

        // Sepak bola cuma punya dua babak.
        $this->act($owner, $org, $match, ['action' => 'advance'])->assertStatus(422);

        $this->assertSame(2, (int) $match->fresh()->period, 'penolakan tidak boleh menaikkan babaknya');
    }

    public function test_reset_zeroes_this_period_without_touching_which_period_it_is(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner, 'football');

        Carbon::setTestNow('2026-09-25 08:00:00');
        $this->act($owner, $org, $match, ['action' => 'start'])->assertOk();
        $this->act($owner, $org, $match, ['action' => 'advance'])->assertOk();

        Carbon::setTestNow('2026-09-25 08:03:00');
        $clock = $this->act($owner, $org, $match, ['action' => 'reset'])->assertOk()->json('data.clock');

        // Yang dikoreksi adalah stopwatch yang menyala kepagian; babaknya tidak
        // ikut turun, karena yang salah cuma satu hal.
        $this->assertSame(0, $clock['elapsed_seconds']);
        $this->assertSame(2, $clock['period']);
    }

    // ---- 6. paritas dua pintu ----

    public function test_both_doors_produce_the_same_clock_for_the_same_fixture(): void
    {
        $owner = User::factory()->create();
        [$org, $event, $match] = $this->scene($owner, 'football');
        $staff = $this->crew($event, 'staff');

        Carbon::setTestNow('2026-09-25 08:00:00');

        $viaStaff = $this->actingAs($staff, 'api')
            ->patchJson(sprintf(self::CLOCK_URL_STAFF, $event->id, $match->id), ['action' => 'start'])
            ->assertOk()
            ->json('data.clock');

        $viaOrganizer = $this->act($owner, $org, $match, ['action' => 'pause'])
            ->assertOk()
            ->json('data.clock');

        // Satu laga, dua pintu, satu service: yang berbeda hanya `running`,
        // karena aksinya memang berbeda.
        $this->assertSame(
            ['period' => 1, 'periods' => 2, 'label' => 'Babak', 'period_minutes' => 45, 'elapsed_seconds' => 0],
            array_diff_key($viaStaff, ['running' => null, 'server_time' => null]),
        );
        $this->assertSame(
            array_diff_key($viaStaff, ['running' => null, 'server_time' => null]),
            array_diff_key($viaOrganizer, ['running' => null, 'server_time' => null]),
        );
        $this->assertTrue($viaStaff['running']);
        $this->assertFalse($viaOrganizer['running']);
    }

    // ---- 7. siapa yang boleh ----

    public function test_the_referee_half_of_the_crew_cannot_run_the_clock_but_staff_can(): void
    {
        $owner = User::factory()->create();
        [, $event, $match] = $this->scene($owner, 'football');

        $referee = $this->crew($event, 'referee', 'wasit@example.test');

        $this->actingAs($referee, 'api')
            ->patchJson(sprintf(self::CLOCK_URL_STAFF, $event->id, $match->id), ['action' => 'start'])
            ->assertStatus(403);

        // Event yang sama, laga yang sama, payload yang sama. Cuma `kind` yang
        // berbeda — jadi cuma `event.staff` yang bisa menjelaskan 403 di atas.
        $staff = $this->crew($event, 'staff', 'petugas@example.test');

        $this->actingAs($staff, 'api')
            ->patchJson(sprintf(self::CLOCK_URL_STAFF, $event->id, $match->id), ['action' => 'start'])
            ->assertOk();
    }

    public function test_an_organization_operator_may_run_the_clock_but_an_outsider_may_not(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner, 'football');

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        // Jam adalah presentasi, bukan hasil resmi — operator yang mengetik skor
        // adalah orang yang sama yang memegang stopwatch, jadi pintunya `tenant`
        // dan bukan `org.admin`.
        $this->act($operator, $org, $match, ['action' => 'start'])->assertOk();

        // Dibandingkan dengan orang luar: tanpa ini, 200 di atas juga keluar dari
        // route yang tidak memeriksa apa pun.
        $this->act(User::factory()->create(), $org, $match, ['action' => 'pause'])->assertStatus(403);
    }

    public function test_staff_cannot_run_the_clock_of_a_sibling_event(): void
    {
        $owner = User::factory()->create();
        [, $mine] = $this->scene($owner, 'football');
        [, $theirs, $theirMatch] = $this->scene($owner, 'football');

        $staff = $this->crew($mine, 'staff');

        // Kedua event milik organizer yang sama, jadi tenant tidak bisa jadi
        // pemisahnya: klaim kru menempel pada satu event.
        $this->actingAs($staff, 'api')
            ->patchJson(sprintf(self::CLOCK_URL_STAFF, $theirs->id, $theirMatch->id), ['action' => 'start'])
            ->assertStatus(403);
    }

    // ---- penolakan lain ----

    public function test_an_unknown_action_is_refused_before_anything_moves(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner, 'football');

        $this->act($owner, $org, $match, ['action' => 'stop'])
            ->assertStatus(422)
            ->assertJsonPath('errors.action.0', 'Aksi jam pertandingan tidak dikenali.');

        $this->assertSame('scheduled', $match->fresh()->status);
    }

    public function test_a_finished_match_refuses_the_clock_while_an_ongoing_one_accepts_it(): void
    {
        $owner = User::factory()->create();
        [$ongoingOrg, , $ongoing] = $this->scene($owner, 'football');
        [$finishedOrg, , $finished] = $this->scene($owner, 'football');

        $finished->forceFill(['status' => 'finished'])->save();

        $this->act($owner, $ongoingOrg, $ongoing, ['action' => 'start'])->assertOk();
        $this->act($owner, $finishedOrg, $finished, ['action' => 'start'])->assertStatus(422);
    }

    public function test_pausing_twice_does_not_fold_the_same_seconds_in_again(): void
    {
        $owner = User::factory()->create();
        [$org, , $match] = $this->scene($owner, 'football');

        Carbon::setTestNow('2026-09-25 08:00:00');
        $this->act($owner, $org, $match, ['action' => 'start'])->assertOk();

        Carbon::setTestNow('2026-09-25 08:00:40');
        $first = $this->act($owner, $org, $match, ['action' => 'pause'])->assertOk()->json('data.clock.elapsed_seconds');

        // Dua petugas menekan Jeda pada laga yang sama.
        Carbon::setTestNow('2026-09-25 08:01:20');
        $second = $this->act($owner, $org, $match, ['action' => 'pause'])->assertOk()->json('data.clock.elapsed_seconds');

        $this->assertSame(40, $first);
        $this->assertSame(40, $second, 'jeda kedua tidak boleh melipat detik yang sama lagi');
    }
}
