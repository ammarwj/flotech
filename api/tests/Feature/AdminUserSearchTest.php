<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pencarian di /admin/users mencocokkan nama & email tanpa peduli besar-kecil
 * huruf, dan memperlakukan apa yang diketik sebagai teks — bukan pola.
 *
 * Peringatan untuk siapa pun yang menyentuh ini: **tes ini berjalan di sqlite,
 * yang LIKE-nya sudah case-insensitive sendiri.** Bug aslinya (LIKE polos, yang
 * di Postgres case-sensitive) tidak akan pernah memerahkan file ini — itu
 * sebabnya ia sempat sampai ke produksi dengan suite hijau. Yang benar-benar
 * dijaga di sini adalah bentuk querynya: `ilike` (pgsql-only) memecahkan tes
 * ini, dan hilangnya ESCAPE terlihat lewat kasus wildcard di bawah. Perubahan
 * apa pun di sini wajib dicoba juga di pgsql.
 */
class AdminUserSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsers(): void
    {
        User::factory()->create(['full_name' => 'Sep Taneo', 'email' => 'kaboax@gmail.com']);
        User::factory()->create(['full_name' => 'Bagas Prakoso', 'email' => 'bagas@example.com']);
    }

    /**
     * @return array<int, string>
     */
    private function search(User $admin, string $q): array
    {
        return $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/users?q='.urlencode($q))
            ->assertOk()
            ->json('data.items.*.email');
    }

    public function test_search_ignores_letter_case_in_name_and_email(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->seedUsers();

        // Dibandingkan, bukan sekadar "ada hasil": daftar yang mengembalikan
        // semua user juga akan memuat email yang dicari.
        foreach (['Kaboax', 'kaboax', 'KABOAX'] as $q) {
            $this->assertSame(['kaboax@gmail.com'], $this->search($admin, $q), "gagal untuk '{$q}'");
        }

        foreach (['bagas', 'BAGAS PRAKOSO'] as $q) {
            $this->assertSame(['bagas@example.com'], $this->search($admin, $q), "gagal untuk '{$q}'");
        }
    }

    public function test_wildcards_typed_into_the_box_are_searched_for_literally(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->seedUsers();

        // Tanpa ESCAPE, '%' dan '_' membuat kotak pencarian mengembalikan
        // seluruh tabel — hasil yang terbaca seperti "filternya tidak jalan".
        $this->assertSame([], $this->search($admin, '%'));
        $this->assertSame([], $this->search($admin, 'kaboax_gmail.com'));
    }

    public function test_search_is_super_admin_only(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/users?q=kaboax')
            ->assertForbidden();
    }
}
