<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Berapa nama yang boleh ditulis manajer di satu team sheet.
 *
 * Satu angka dibaca dua kali dengan dua operator, dan itulah yang setiap test di
 * sini **bandingkan**: sheet yang sama, dua pintu, dua jawaban. Assert "submit()
 * menolak sepuluh" saja akan lolos walau `sync()` diam-diam ikut menolaknya dan
 * manajer tidak pernah bisa menyimpan pekerjaan setengah jadi — persis kegagalan
 * yang gate ini sebenarnya punya.
 *
 * Cabangnya sungguhan, dari seeder. Cabang sintetis dengan config buatan tangan
 * adalah cara bug `platform_fee_percent` lolos dua belas test hijau.
 */
class LineupSquadSizeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->org = $this->orgFor($this->owner);
    }

    // ---- fixtures ----

    /** @param  array<string, mixed>  $rules  isi rules_config['squad'] */
    private function event(string $sport = 'football', array $rules = []): Event
    {
        $event = $this->eventOn($this->org, attrs: [
            'sport_type' => $sport,
            'rules_config' => $rules === [] ? null : ['squad' => $rules],
        ]);

        $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    private function categoryId(Event $event): string
    {
        return $event->categories->first()->id;
    }

    /**
     * Tim dengan akun manajer di belakangnya, didaftarkan lewat form publik —
     * itu yang mengisi `teams.manager_user_id`.
     *
     * @return array{manager: User, id: string, players: array<int, string>}
     */
    private function team(Event $event, string $name, int $size): array
    {
        $manager = User::factory()->create();

        $data = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$this->org->slug}/{$event->slug}/register", [
                'category_id' => $this->categoryId($event),
                'name' => $name,
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => array_map(
                    fn (int $i) => ['full_name' => "{$name} {$i}", 'jersey_number' => (string) $i],
                    range(1, $size),
                ),
            ])
            ->assertCreated()
            ->json('data.team');

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/registrations/{$data['id']}", [
                'status' => 'approved',
            ])
            ->assertOk();

        return [
            'manager' => $manager,
            'id' => $data['id'],
            'players' => array_column($data['players'], 'id'),
        ];
    }

    private function fixture(Event $event, string $home, string $away): string
    {
        return $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/categories/{$this->categoryId($event)}/matches", [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => null,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array{manager: User, id: string, players: array<int, string>}  $side
     * @return array<int, array<string, mixed>>
     */
    private function sheet(array $side, int $starters, int $substitutes = 0): array
    {
        $rows = [];

        foreach (array_slice($side['players'], 0, $starters + $substitutes) as $i => $id) {
            $rows[] = ['player_id' => $id, 'role' => $i < $starters ? 'starter' : 'substitute'];
        }

        return $rows;
    }

    /** @param  array<int, array<string, mixed>>  $players */
    private function save(array $side, string $match, array $players)
    {
        return $this->actingAs($side['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$side['id']}/matches/{$match}/lineup", [
                'players' => $players,
                'officials' => [],
            ]);
    }

    private function submit(array $side, string $match)
    {
        return $this->actingAs($side['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$side['id']}/matches/{$match}/lineup/submit");
    }

    // ---- tests ----

    public function test_a_half_filled_draft_saves_but_is_not_handed_to_the_referee(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', 20);
        $away = $this->team($event, 'Rajawali United', 20);
        $match = $this->fixture($event, $home['id'], $away['id']);

        // Sepuluh inti: sah sebagai draf, dan itulah satu-satunya alasan angka
        // ini dibaca dua kali. Assert penolakan submit() saja akan lolos walau
        // sync() ikut menolaknya dan manajer terkunci di luar formulirnya.
        $this->save($home, $match, $this->sheet($home, 10))->assertOk();

        $this->submit($home, $match)->assertStatus(422);

        // Satu nama lagi, permintaan yang sama persis — cuma hitungannya beda.
        $this->save($home, $match, $this->sheet($home, 11))->assertOk();

        $this->submit($home, $match)
            ->assertOk()
            ->assertJsonPath('data.lineup.status', 'submitted');
    }

    public function test_the_twelfth_starter_is_refused_at_both_doors(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', 20);
        $away = $this->team($event, 'Rajawali United', 20);
        $match = $this->fixture($event, $home['id'], $away['id']);

        // Batas atas berlaku di kedua pintu; yang longgar cuma batas bawahnya.
        $this->save($home, $match, $this->sheet($home, 12))->assertStatus(422);

        $this->assertDatabaseCount('match_lineup_players', 0);
    }

    public function test_the_bench_has_its_own_ceiling(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', 25);
        $away = $this->team($event, 'Rajawali United', 20);
        $match = $this->fixture($event, $home['id'], $away['id']);

        // 11 + 7 = batas sepak bola, dan tepat di batas ia lewat.
        $this->save($home, $match, $this->sheet($home, 11, 7))->assertOk();

        // Cadangan kedelapan: ditolak walau intinya masih sebelas — dua angka,
        // dua batas, dan yang ini tidak boleh ikut terhitung ke yang itu.
        $this->save($home, $match, $this->sheet($home, 11, 8))->assertStatus(422);

        $this->assertDatabaseCount('match_lineup_players', 18);
    }

    public function test_the_sport_brings_its_own_numbers(): void
    {
        // Futsal 5 inti, sepak bola 11 — permintaan yang sama, dua cabang, dan
        // cuma katalognya yang bisa menjelaskan bedanya. Dibandingkan justru
        // karena angka sepak bola adalah DEFAULTS: assert futsal saja akan lolos
        // walau `sports.squad_config` tidak pernah dibaca.
        $futsal = $this->event('futsal');
        $home = $this->team($futsal, 'Garuda FS', 15);
        $away = $this->team($futsal, 'Rajawali FS', 15);
        $match = $this->fixture($futsal, $home['id'], $away['id']);

        $this->save($home, $match, $this->sheet($home, 5))->assertOk();
        $this->submit($home, $match)->assertOk();

        $this->save($home, $match, $this->sheet($home, 11))->assertStatus(422);

        $football = $this->event();
        $fHome = $this->team($football, 'Garuda FC', 15);
        $fAway = $this->team($football, 'Rajawali United', 15);
        $fMatch = $this->fixture($football, $fHome['id'], $fAway['id']);

        $this->save($fHome, $fMatch, $this->sheet($fHome, 11))->assertOk();
        $this->submit($fHome, $fMatch)->assertOk();
    }

    public function test_the_event_overrides_the_sport_and_a_cleared_field_does_not(): void
    {
        // Turnamen 7-a-side di cabang sepak bola: inti diketik, bangku
        // dikosongkan. Yang dikosongkan harus **mewarisi** tujuh milik cabang,
        // bukan jadi nol — itu seluruh alasan `clean()` membuang null.
        $event = $this->event('football', ['starters' => 7, 'max_substitutes' => null]);
        $home = $this->team($event, 'Garuda FC', 20);
        $away = $this->team($event, 'Rajawali United', 20);
        $match = $this->fixture($event, $home['id'], $away['id']);

        $this->save($home, $match, $this->sheet($home, 7, 7))->assertOk();
        $this->submit($home, $match)->assertOk();

        // Sebelas adalah angka cabangnya, dan di sini ia harus ditolak.
        $this->save($home, $match, $this->sheet($home, 11))->assertStatus(422);
    }

    public function test_a_set_sport_has_no_team_sheet_size_at_all(): void
    {
        // Voli beregu dan punya enam di lapangan, tapi rotasi dan libero bukan
        // "inti vs cadangan" — angka apa pun di sini akan berbohong. Gate-nya
        // dari katalog, jadi cabang set tidak lewat sini sama sekali.
        $event = $this->event('volleyball');
        $home = $this->team($event, 'Garuda VC', 14);
        $away = $this->team($event, 'Rajawali VC', 14);
        $match = $this->fixture($event, $home['id'], $away['id']);

        $data = $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['id']}/matches/{$match}/lineup")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['squad_rules']);

        // Empat belas nama — jauh di atas batas sepak bola, dan tidak ada yang
        // menghitungnya.
        $this->save($home, $match, $this->sheet($home, 14))->assertOk();
        $this->submit($home, $match)->assertOk();
    }

    public function test_an_empty_sheet_is_never_handed_in_whatever_the_sport(): void
    {
        $event = $this->event('volleyball');
        $home = $this->team($event, 'Garuda VC', 14);
        $away = $this->team($event, 'Rajawali VC', 14);
        $match = $this->fixture($event, $home['id'], $away['id']);

        // Cabang tanpa ukuran susunan tetap tidak boleh menyerahkan formulir
        // kosong ke wasit — satu-satunya aturan yang bertahan di sana.
        $this->submit($home, $match)->assertStatus(422);

        $this->save($home, $match, $this->sheet($home, 1))->assertOk();
        $this->submit($home, $match)->assertOk();
    }

    public function test_the_editor_is_told_the_size_before_the_manager_fills_it(): void
    {
        $event = $this->event('futsal');
        $home = $this->team($event, 'Garuda FS', 15);
        $away = $this->team($event, 'Rajawali FS', 15);
        $match = $this->fixture($event, $home['id'], $away['id']);

        // Proaktif sekaligus reaktif, bentuk yang sama dengan gate paket: editor
        // menghentikan manajer di angka itu alih-alih membiarkannya mengisi
        // formulir lalu bertemu 422.
        $rules = $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['id']}/matches/{$match}/lineup")
            ->assertOk()
            ->json('data.squad_rules');

        $this->assertSame(5, $rules['starters']);
        $this->assertSame(9, $rules['max_substitutes']);
        $this->assertSame(14, $rules['max_players']);
    }
}
