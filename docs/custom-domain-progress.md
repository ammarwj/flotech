# Progress: custom domain per event

Setiap event yang sudah publish bisa diberi domain sendiri (`eventa.id`,
`eventb.jktevent.com`, atau subdomain `floevent.id`) oleh super admin, lengkap
dengan penerbitan SSL otomatis. Event tanpa domain tetap memakai URL sekarang.

- **Branch**: `master`
- **Baseline sebelum perubahan** (2026-09-06): backend `459 lulus / 0 gagal`

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan
trailer `Co-Authored-By:` atau `Claude-Session:`.

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`,
> `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman;
> `php artisan test` aman (sqlite in-memory).

---

## Keputusan produk (dikonfirmasi user 2026-09-06)

| Hal | Keputusan |
|---|---|
| Cakupan | Halaman event + beli tiket. Sisanya 301 ke `floevent.id`. |
| Bentuk URL | Root = halaman event (`eventa.id/`), `eventa.id/tickets` = beli tiket. |
| Pendaftaran tim | Tetap di `floevent.id` — butuh login, dan sesi tidak bisa ikut. |
| Widget akun | Disembunyikan di custom domain. |
| URL lama | `floevent.id/{org}/{event}` → 301 ke custom domain (kecuali `/register`). |
| Gating paket | **Tidak ada** — murni keputusan super admin. |
| SSL | Otomatis penuh dari tombol di panel admin. |
| Cabut domain | Berhenti dilayani + sertifikat dihapus. |

Feature key `custom_domain` **sengaja tidak dihidupkan kembali**. Ia pernah dijual
di kartu paket tanpa pernah ditegakkan, lalu dipensiunkan di
`2026_08_01_100003_seed_per_event_plan_catalogue.php` justru supaya kartu harga
berhenti menjanjikannya. Kalau nanti mau dijual, key-nya ditambahkan lagi ke
`PlanSeeder` + `FeatureDefinitionSeeder` **bersamaan** dengan gate-nya di titik
aktivasi — jangan hidupkan key-nya sebelum gate-nya benar-benar ada.

---

## Invarian

> **1. Sesi tidak bisa ikut pindah domain.** Refresh cookie terikat
> `SESSION_DOMAIN=.floevent.id`, jadi browser tidak akan pernah mengirimkannya ke
> `eventa.id`. Membuatnya ikut berarti cookie sesi platform terkirim ke domain yang
> dikendalikan organizer. Karena itu pendaftaran tim tetap di domain utama dan
> `PublicAuthActions` disembunyikan. Beli tiket tidak butuh login, jadi ia ikut penuh.

> **2. Jangan pernah `default_server` atau `server_name ~^.+$`.** VPS berbagi nginx
> dengan stack `runup`: `default_server` hanya boleh ada satu per port, dan regex
> catch-all dievaluasi **sebelum** `default_server` sehingga akan mencuri trafik
> vhost tetangga. Config digenerate dengan daftar `server_name` **eksplisit** dari DB.

> **3. Status domain diturunkan, tidak disimpan.** `Event::domainStatus()` membaca
> `custom_domain` / `domain_certified_at` / `domain_error`. Kolom status tersendiri
> akan menyimpang dari kenyataan di disk begitu sertifikat dihapus atau gagal renew —
> kelas bug yang sama dengan larangan bermain yang disimpan.

> **4. Blok nginx port 80 ditulis SEBELUM certbot dipanggil.** Tanpa `server_name`-nya
> terdaftar duluan, challenge ACME untuk domain baru jatuh ke vhost tetangga dan
> selalu gagal. Aktivasi = dua reload (daftar → terbitkan → sajikan).

> **5. Rate limit Let's Encrypt dijaga dua lapis.** 5 kegagalan/jam/hostname, dan
> kuotanya per akun. `issue()` tidak memanggil certbot sebelum `verifyDns()` lolos,
> dan domain yang baru saja gagal dilewati sampai backoff-nya habis. Tanpa keduanya
> satu domain salah DNS mengunci domain lain.

---

## Tahapan

### Tahap 1 — Skema + config
- [x] Migrasi `add_custom_domain_to_events_table`
- [x] `Event`: fillable, casts, `domainStatus()`
- [x] `config/domains.php`

### Tahap 2 — Backend
- [x] `DomainService` + `DomainException` (dirender di `bootstrap/app.php`)
- [x] `Admin\EventController` + `UpdateEventDomainRequest` + `AdminEventResource`
- [x] `Public\DomainController` (`GET public/domains`)
- [x] CORS dinamis (`DynamicCors`, dipasang lewat `$middleware->replace()`)
- [x] `domains:sync` (tiap menit) + `domains:renew` (harian 03:30)
- [x] `CustomDomainTest` — 13 test, **suite penuh 502 lulus / 0 gagal**

### Tahap 3 — Panel admin
- [x] `/admin/events` + `lib/api/admin-events.ts` + tipe `AdminEvent`/`DomainStatus`
- [x] Entri nav "Event & Domain" di grup `admin-system`

### Tahap 4 — Frontend publik
- [x] `proxy.ts` (routing per-host) + `proxy.test.ts` — **5 test lulus** (`bun test`)
- [x] `lib/event-base.tsx` + `[eventSlug]/layout.tsx` (provider server component)
- [x] Penyesuaian `page.tsx` & `tickets/page.tsx`: `base`, `registerHref` absolut,
      `PublicAuthActions` disembunyikan, tautan platform absolut

### Tahap 5 — Infra
- [x] `api/Dockerfile`: `certbot` di stage `base`
- [x] `docker-compose.yml`: bind-mount `/opt/flo-event/*` di `api` **dan** `scheduler`
      (anchor `&domain_volumes`/`*domain_volumes` — lihat bug di bawah),
      `INTERNAL_API_URL` di `environment:` service `web` (bukan `args:`)
- [x] `deploy/host-nginx/flo-event-domains.conf` + `flo-domains-reload.{path,service}`
- [x] `deploy/setup-custom-domains.sh` + bab 9 di `deploy.md`
- [x] Entri `.env.example` di kedua sisi

---

## Bug produksi (2026-09-06/08): tombol "Aktifkan" selalu gagal di klik pertama

**Gejala**: klik "Aktifkan & terbitkan SSL" di `/admin/events` gagal dengan pesan
generik certbot ("Ask for help or search for solutions at
https://community.letsencrypt.org...").

**Akar masalah**: rencana awal cuma memasang bind-mount `/opt/flo-event/*` di
`scheduler` — asumsinya certbot selalu dijalankan `domains:sync`. Tapi tombol
"Aktifkan" memanggil `EventController::activateDomain()` → `DomainService::issue()`
**sinkron di container `api`**, yang tidak punya mount itu sama sekali. `publish()`
diam-diam no-op (`is_dir()` gagal), jadi blok port-80 domain baru **tidak pernah**
ditulis ke nginx host, dan `certbotCommand()` menulis config-dir/webroot certbot ke
path yang tidak terlihat host mana pun. Challenge HTTP-01 pasti 404 — klik pertama
dijamin gagal untuk domain manapun yang belum pernah disentuh `domains:sync`.

**Bug kedua yang memperparah**: `lastMeaningfulLine()` mengambil baris terakhir
output certbot secara naif — yang selalu berupa footer generik "Ask for help...",
bukan baris `Detail: ...` yang sebenarnya menjelaskan kegagalan (biasanya beberapa
baris di atasnya).

**Perbaikan**:
1. `docker-compose.yml` — pindahkan bind-mount jadi milik `api` (anchor
   `&domain_volumes`), `scheduler` alias (`*domain_volumes`) supaya keduanya tidak
   bisa berselisih lagi. Divalidasi dengan `docker compose config`.
2. `DomainService::lastMeaningfulLine()` — cari baris berawalan `Detail:` dari
   belakang dulu, baru jatuh ke baris terakhir kalau tidak ada.
3. Docblock `SyncCustomDomains` yang tadinya (salah) mendokumentasikan asimetri
   mount sebagai desain — diperbaiki.
4. Test baru `test_failed_activation_surfaces_the_certbot_detail_line_not_the_footer`
   di `CustomDomainTest.php` — membandingkan bahwa `domain_error` memuat `Detail:`
   dan **bukan** "Ask for help", dengan output certbot palsu yang punya keduanya.

**Verifikasi**: `php artisan test` — 503 lulus / 3161 assertion / 0 gagal.
`docker compose config` — kedua service membawa keempat mount yang identik.

**Deploy ke VPS**: rebuild `api` bersamaan dengan service lain yang sudah ada di
instruksi `deploy.md` (`docker compose up -d --build api worker scheduler web`) —
sebelumnya `api` tidak perlu di-rebuild untuk perubahan compose murni, tapi kali
ini volumenya berubah jadi container `api` harus di-recreate.

**Event yang sudah kadung gagal di VPS**: `activateDomain()` tidak mengecek
backoff `pending()` (itu hanya dibaca `domains:sync`), jadi cukup klik "Aktifkan"
lagi sesudah deploy fix ini — tidak perlu reset manual `domain_attempted_at`
lewat tinker/SQL.

---

## Status verifikasi (2026-09-06)

- `php artisan test` — **503 lulus / 3161 assertion / 0 gagal** (sesudah bug
  bind-mount `api` di bawah diperbaiki)
- `bun test proxy.test.ts` — **5 lulus / 0 gagal**
- `bunx tsc --noEmit` — bersih
- `bun run build` — sukses, `ƒ Proxy (Middleware)` terdaftar, tidak ada lagi
  peringatan deprekasi `middleware`
- `bun run lint` — 1 error + 9 warning, **semuanya sudah ada sebelum fitur ini**
  (`participant/teams/[id]/page.tsx:60`, file yang tidak disentuh)

Belum dijalankan — butuh tangan manusia karena mengubah `/etc/hosts` (sudo) dan
menulis ke DB dev yang merupakan salinan produksi:

- [ ] Uji lokal: `127.0.0.1 eventa.test` di `/etc/hosts`, satu event dengan
      `custom_domain=eventa.test` + `domain_certified_at`, lalu cek root,
      `/tickets`, 301 untuk path lain, `/register` yang tetap dilayani, dan
      widget akun yang hilang
- [ ] Uji di VPS sesudah rilis (langkah-langkahnya di bab 9 `deploy.md`)

---

## Catatan implementasi

**Header, bukan base path kosong.** Proxy mengirim `x-flo-custom-domain`
berisi hostname-nya, bukan "base path yang kosong": string kosong tidak bisa
dibedakan dari header yang tidak ada. Header itu **selalu ditulis ulang** —
dihapus eksplisit di domain utama — supaya klien tidak bisa memalsukannya lewat
header request dan membuat halaman di `floevent.id` menyembunyikan widget akun.
Ada testnya (`proxy.test.ts`).

**Namanya `proxy.ts`, bukan `middleware.ts`** — Next 16 mendeprekasi nama yang
lama. Runtime `proxy` selalu nodejs dan tidak bisa diubah; itu justru yang
dibutuhkan di sini karena ia memanggil `http://api:8000` di dalam jaringan Docker.

**Provider-nya server component.** `[eventSlug]/layout.tsx` membaca `headers()`;
deteksi di klien lewat `window.location` akan cocok satu render terlambat,
sesudah hydration mismatch. Halaman-halamannya toh sudah client-fetch penuh,
jadi tidak ada static rendering yang dikorbankan.

**Reload flag ada di direktorinya sendiri** (`/opt/flo-event/flags/reload.flag`,
bukan `/opt/flo-event/reload.flag`) karena direktori itulah yang di-bind-mount:
bind-mount sebuah file putus begitu file-nya diganti alih-alih ditulis ulang.
