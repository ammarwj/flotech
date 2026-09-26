<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * "Login sebagai" seorang wasit/staf yang baru diundang.
 *
 * Akun petugas adalah satu-satunya akun di platform ini yang lahir dengan
 * `must_change_password`, dan itu membuat jalur dukungan bertabrakan dengan
 * kuncinya sendiri: tokennya sah, tapi `password.rotated` menolak seluruh
 * permukaan tugas, sementara di web `AuthGate` merender layar ganti-password
 * **menggantikan** children — termasuk tombol "Kembali ke admin". Admin
 * terkurung, dan satu-satunya tombol di depannya menulis kredensial wasit yang
 * sesungguhnya.
 *
 * Dua hal di sini cuma bisa dibuktikan dengan **membandingkan**:
 *
 *  - Kuncinya masih terkunci. Assert "token impersonasi lolos" saja sama
 *    lolosnya untuk middleware yang dihapus seluruhnya — yang justru
 *    mempersilakan password undangan yang sudah dibaca orang lain masuk. Akun
 *    yang sama, event yang sama, dua kredensial.
 *  - `officiating` ikut terkirim. Assert responsnya 200 tidak mengatakan apa pun
 *    soal apakah shell tahu ini petugas; tanpa relasinya, admin mendarat di
 *    dashboard organizer kosong milik seorang wasit.
 */
class ImpersonateCrewTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** Event dengan satu petugas, dan akun yang baru saja dibuatkan untuknya. */
    private function crewOn(Event $event, string $kind = 'referee'): User
    {
        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => $kind, 'email' => 'wasit@example.test'],
        ]);

        return User::where('email', 'wasit@example.test')->firstOrFail();
    }

    private function event(): Event
    {
        return $this->eventOn($this->orgFor(User::factory()->create()));
    }

    /**
     * Kirim request berikutnya dengan token ini, dan **hanya** token ini.
     *
     * Dua keadaan-bersama harus dibuang dulu, dan keduanya gagal dengan diam:
     *
     *  - Guard menyimpan user dari panggilan sebelumnya (alasan yang sama sudah
     *    ditulis soal `actingAs()` di ImpersonationTest).
     *  - Instance `tymon.jwt` adalah singleton yang **menyimpan token terakhir
     *    yang dicetak atau di-parse**, dan `getToken()` memilihnya sebelum
     *    menyentuh request. Tanpa `unsetToken()`, header di sini diabaikan dan
     *    assertion-nya menguji token dari paruh tes sebelumnya — yang membuat
     *    setengah 403 di bawah balas 200 dan terlihat persis seperti middleware
     *    yang bocor.
     */
    private function asBearer(string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Inti perbaikannya: token `act_as` melewati `password.rotated`, password
     * undangannya tidak.
     *
     * Dua **bearer token sungguhan untuk akun yang sama**, dengan flag yang
     * sama, ke event yang sama — jadi tidak ada penjelasan lain untuk bedanya
     * selain klaim di tokennya. Setengah 403-nya bukan pelengkap: tanpanya tes
     * ini sama lolosnya untuk middleware yang dihapus seluruhnya, yang justru
     * mempersilakan password undangan yang sudah dibaca orang lain masuk.
     *
     * Dua jebakan keadaan-bersama, keduanya diam:
     *
     *  - Token biasanya **wajib dicetak lebih dulu**. `issueImpersonationToken()`
     *    memanggil `JWTAuth::customClaims([...])`, dan claim itu menempel di
     *    instance JWT yang dibagi seluruh request test — token apa pun yang
     *    dicetak sesudahnya ikut membawa `act_as`, sehingga setengah 403-nya
     *    balas 200 dan kelihatan seperti bug di middleware.
     *  - Keadaan guard & token di antara keduanya — lihat `asBearer()`.
     */
    public function test_an_impersonation_token_opens_the_duty_surface_the_default_password_cannot(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $event = $this->event();
        $referee = $this->crewOn($event);

        $this->assertTrue($referee->must_change_password, 'baru diundang, jadi flag-nya masih ada');

        $url = "/api/v1/officiating/events/{$event->id}";

        // Dicetak sebelum ada customClaims di instance JWT — lihat docblock.
        $ownToken = auth('api')->login($referee);
        $actAsToken = app(AuthService::class)->issueImpersonationToken($referee, $admin);

        // Kredensial wasit itu sendiri: tetap ditolak.
        $this->asBearer($ownToken)
            ->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('errors.code', ['must_change_password']);

        // Admin yang bertindak sebagai dia: lolos, tanpa ada yang diganti.
        $this->asBearer($actAsToken)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.assignment.kind', 'referee');

        // Dan flag-nya tidak tersentuh — jalur dukungan tidak boleh diam-diam
        // memutar kredensial orang lain hanya karena admin melihat halamannya.
        $this->assertTrue($referee->fresh()->must_change_password);
    }

    /**
     * Respons `impersonate()` **adalah** user shell-nya, jadi ia harus membawa
     * `officiating` — itu yang dibaca ModeSwitcher dan halaman tujuan.
     */
    public function test_the_impersonate_response_carries_the_crew_assignments(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $event = $this->event();
        $referee = $this->crewOn($event);

        $user = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$referee->id}/impersonate")
            ->assertOk()
            ->json('data.user');

        $this->assertSame([$event->name], array_column($user['officiating'], 'event_name'));
        $this->assertSame(['referee'], array_column($user['officiating'], 'kind'));
        // Dan `default_mode` memang 'officiating', nilai yang dipakai frontend
        // untuk memilih halaman tujuan — jadi tipenya di TS harus selebar ini.
        $this->assertSame('officiating', $user['default_mode']);
        // Dibandingkan dengan account_types yang kosong: keduanya di satu
        // respons, karena assert `officiating` ada saja juga lolos untuk resource
        // yang mulai mengirim semuanya.
        $this->assertSame([], $user['account_types']);
    }
}
