<?php

namespace Tests\Feature;

use App\Services\Catalog;
use App\Support\DisciplineRules;
use App\Support\MatchClockRules;
use App\Support\SquadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cabang yang tidak berpertandingan sama sekali: `sports.scoring = 'none'`.
 *
 * Tiap uji di sini **membandingkan fun run dengan sepak bola**, bukan memeriksa
 * fun run sendirian. Alasannya konkret: kedua gate yang diuji dulu ditulis
 * sebagai negasi (`! isSetBased($slug) && sport($slug) !== null`), dan sebuah
 * assert tunggal `assertFalse($funRun->enabled)` akan tetap hijau walau
 * seseorang mengubahnya jadi `false` konstan — yang mematikan jam dan susunan
 * pemain untuk sepuluh cabang lainnya sekaligus. Pasangannya yang mengikat
 * kedua arah.
 *
 * Katalognya katalog sungguhan (`CatalogSeeder` lewat `TestCase::$seeder`,
 * ditambah migrasi yang menyemai `fun_run`), bukan cabang sintetis: yang diuji
 * justru nilai `scoring` yang tersimpan di baris aslinya.
 */
class FunRunGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fun_run_is_in_the_catalogue_and_scored_as_none(): void
    {
        // Dibandingkan dengan sepak bola: assert `'none'` sendirian akan lolos
        // walau pembacaan `scoring` rusak dan setiap cabang menjawab sama.
        $this->assertSame('none', Catalog::scoringOf('fun_run'));
        $this->assertSame('goal', Catalog::scoringOf('football'));
        $this->assertSame('set', Catalog::scoringOf('badminton'));

        // Dan cabang yang tidak ada tetap bisa dibedakan dari cabang yang ada
        // tapi tak berpertandingan — itulah kenapa scoringOf() nullable.
        $this->assertNull(Catalog::scoringOf('kurash'));
    }

    public function test_only_fun_run_is_non_competitive(): void
    {
        $this->assertFalse(Catalog::isCompetitive('fun_run'));

        // Keduanya, karena `isCompetitive` menerima dua nilai: assert sepak
        // bola saja akan lolos walau ia ditulis `=== 'goal'` dan diam-diam
        // mengeluarkan setiap cabang set dari klasemen.
        $this->assertTrue(Catalog::isCompetitive('football'));
        $this->assertTrue(Catalog::isCompetitive('badminton'));

        // Cabang tak dikenal bukan cabang non-kompetitif. Bedanya load-bearing:
        // `isSetBased()` men-default ke 'goal' di sini, dan gate yang dibangun
        // di atas default itu persis yang meloloskan fun run.
        $this->assertFalse(Catalog::isCompetitive('kurash'));
    }

    /**
     * Jam pertandingan & susunan pemain mati untuk fun run, hidup untuk sepak
     * bola. Satu test untuk dua gate karena keduanya memang **satu ekspresi
     * yang literal identik** — dipisah jadi dua test, cabang yang satu bisa
     * dibalik sendiri dan test sebelahnya tetap hijau.
     */
    public function test_clock_and_squad_are_off_for_fun_run_and_on_for_football(): void
    {
        $runClock = MatchClockRules::forSport('fun_run');
        $runSquad = SquadRules::forSport('fun_run');

        $ballClock = MatchClockRules::forSport('football');
        $ballSquad = SquadRules::forSport('football');

        $this->assertFalse($runClock->enabled);
        $this->assertFalse($runSquad->enabled);

        // Separuh yang mengikat: tanpa ini, `false` konstan lolos.
        $this->assertTrue($ballClock->enabled);
        $this->assertTrue($ballSquad->enabled);
    }

    /**
     * Gejala kalau gate-nya negasi: bukan error, tapi angka karangan.
     *
     * Fun run tidak punya `period_config`/`squad_config` sama sekali, jadi
     * `max(1, …)` di kedua kelas mengarang satu babak satu menit dan sebelas
     * pemain inti. Yang membuatnya berbahaya adalah klien membaca `toArray()`:
     * null berarti "cabang ini tak berjam", dan sebuah array berarti papan skor
     * memasang "Babak 1" di atas lomba yang tidak punya babak.
     */
    public function test_fun_run_publishes_no_clock_or_squad_rules_at_all(): void
    {
        $this->assertNull(MatchClockRules::forSport('fun_run')->toArray());
        $this->assertNull(SquadRules::forSport('fun_run')->toArray());

        // Sepak bola menerbitkan keduanya — dan angkanya angka aslinya, bukan
        // `max(1, 0)`. Itu yang memisahkan "gate mati" dari "gate hidup dengan
        // nilai kosong", dua keadaan yang assert null sendirian tidak bisa beda.
        $ballClock = MatchClockRules::forSport('football')->toArray();
        $ballSquad = SquadRules::forSport('football')->toArray();

        $this->assertSame(2, $ballClock['periods']);
        $this->assertSame(45, $ballClock['period_minutes']);
        $this->assertSame(11, $ballSquad['starters']);
    }

    /**
     * Disiplin kartu mati tanpa disentuh sama sekali, dan itu memang desainnya:
     * gate-nya membaca role stat (`yellow`/`red`), bukan `scoring`. Fun run
     * tidak punya satu pun baris stat, jadi ia ikut mati lewat aturan yang
     * sudah ada — nol kode baru.
     */
    public function test_discipline_is_off_for_fun_run_through_the_existing_stat_gate(): void
    {
        $this->assertFalse(DisciplineRules::forSport('fun_run')->enabled);

        // Voli membuktikan gate-nya bukan `scoring`: ia beregu, berskor set,
        // punya leaderboard — dan tetap mati di sini karena tak punya kolom
        // kartu. Sepak bola hidup. Tiga cabang, satu aturan.
        $this->assertFalse(DisciplineRules::forSport('volleyball')->enabled);
        $this->assertTrue(DisciplineRules::forSport('football')->enabled);
    }
}
