<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Katalog publik memberi tiap event tepat satu halaman.
 *
 * Bug yang sudah terjadi: `ORDER BY` cuma punya dua kunci (tier status lalu
 * `start_date`), jadi dua event `ongoing` dengan tanggal mulai yang sama **seri
 * di keduanya**. Urutan yang tidak ditentukan + LIMIT/OFFSET memotong tepat di
 * tengah seri itu — satu event terbit di dua halaman sementara kembarnya lenyap
 * dari katalog, padahal `total` tetap benar dan pencarian tetap menemukannya
 * (gejalanya persis itu: "kalau di-search baru ada"). Tiebreaker `id` yang
 * menutupnya.
 *
 * Seperti PublicEventSearchTest: **sqlite tidak bisa memerahkan bug ini** —
 * urutan baris tanpa ORDER BY yang lengkap kebetulan stabil di sana, sementara
 * Postgres bebas menukarnya per query. Yang dijaga file ini adalah kontraknya
 * (gabungan semua halaman = seluruh katalog, tanpa duplikat), dan perubahan
 * ordering wajib dicoba juga di pgsql.
 */
class PublicEventPaginationTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    public function test_every_event_lands_on_exactly_one_page(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // Lima event yang seri di **kedua** kunci urut, dengan batas halaman
        // (per_page=2) jatuh di tengahnya. Event yang tanggalnya berbeda tidak
        // membuktikan apa pun — serinya yang jadi subjek.
        for ($i = 1; $i <= 5; $i++) {
            $this->eventOn($org, null, [
                'name' => "Tie Cup {$i}",
                'status' => 'ongoing',
                'start_date' => '2026-10-04',
                'end_date' => '2026-10-10',
            ]);
        }

        $seen = [];
        $pages = 0;

        do {
            $pages++;
            $body = $this->getJson("/api/v1/public/events?per_page=2&page={$pages}")
                ->assertOk()
                ->json('data');

            $seen = [...$seen, ...array_column($body['items'], 'name')];
        } while ($pages < $body['meta']['last_page']);

        sort($seen);

        // Dua assert, dan keduanya perlu: daftar yang menduplikasi satu baris
        // dan menjatuhkan baris lain punya `count` yang benar.
        $this->assertSame(5, count($seen));
        $this->assertSame(
            ['Tie Cup 1', 'Tie Cup 2', 'Tie Cup 3', 'Tie Cup 4', 'Tie Cup 5'],
            $seen,
        );
    }
}
