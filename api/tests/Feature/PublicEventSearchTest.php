<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Katalog event publik dicari lewat nama event, lokasi, dan nama penyelenggara.
 *
 * Sama seperti AdminUserSearchTest: **sqlite tidak bisa memerahkan bug
 * case-sensitivity-nya** (LIKE di sana sudah case-insensitive), jadi yang benar
 * -benar dijaga file ini adalah bentuk querynya — `ilike` yang dulu dipakai di
 * sini cuma sah di Postgres dan memecahkan tes ini — plus wildcard yang harus
 * dicari sebagai huruf. Perubahan apa pun wajib dicoba juga di pgsql.
 */
class PublicEventSearchTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function seedCatalog(): void
    {
        $jakarta = $this->orgFor(User::factory()->create(), 'Jakarta Sports');
        $this->eventOn($jakarta, null, [
            'name' => 'Piala Kemerdekaan',
            'slug' => 'piala-kemerdekaan',
            'location_name' => 'Gelora Bung Karno',
        ]);

        $bandung = $this->orgFor(User::factory()->create(), 'Bandung EO');
        $this->eventOn($bandung, null, [
            'name' => 'Turnamen Futsal',
            'slug' => 'turnamen-futsal',
            'location_name' => 'GOR Bandung',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function search(string $term): array
    {
        return $this->getJson('/api/v1/public/events?search='.urlencode($term))
            ->assertOk()
            ->json('data.items.*.name');
    }

    public function test_search_matches_name_location_and_organizer_regardless_of_case(): void
    {
        $this->seedCatalog();

        // Dua event dibandingkan, bukan satu: daftar yang mengabaikan filter
        // juga memuat event yang dicari.
        $this->assertSame(['Piala Kemerdekaan'], $this->search('piala'));
        $this->assertSame(['Piala Kemerdekaan'], $this->search('PIALA'));

        // Lokasi.
        $this->assertSame(['Piala Kemerdekaan'], $this->search('gelora bung'));

        // Nama penyelenggara — kolom di tabel lain, lewat relasi.
        $this->assertSame(['Turnamen Futsal'], $this->search('BANDUNG eo'));
    }

    public function test_wildcards_typed_by_a_visitor_are_searched_for_literally(): void
    {
        $this->seedCatalog();

        // Tanpa ESCAPE, satu '%' mengembalikan seluruh katalog.
        $this->assertSame([], $this->search('%'));
        $this->assertSame([], $this->search('Piala_Kemerdekaan'));
    }

    public function test_drafts_stay_out_of_the_search_results(): void
    {
        $this->seedCatalog();

        $org = Organization::where('name', 'Jakarta Sports')->firstOrFail();
        $this->eventOn($org, null, [
            'name' => 'Piala Rahasia',
            'slug' => 'piala-rahasia',
            'status' => 'draft',
        ]);

        $this->assertSame(['Piala Kemerdekaan'], $this->search('piala'));
    }
}
