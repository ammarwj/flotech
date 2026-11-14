# Add-on domain — progress

Add-on berbayar di atas paket: organizer memasang domainnya sendiri, memesan
domain baru dari flo-event, atau memakai subdomain `{sesuatu}.floevent.id`.
Domain aktif membuat halaman event **white-label**: hilang dari katalog `/event`
dan tanpa tautan balik "Didukung flo-event" maupun footer platform.

Infra custom domain (certbot HTTP-01, generator nginx, rewrite `proxy.ts`, panel
super_admin) **sudah ada** sejak `docs/custom-domain-progress.md`. Yang
ditambahkan di sini cuma lapisan komersialnya + subdomain + white-label.

## Keputusan yang sudah final

1. **Add-on nempel di `event_plan_orders`, dan bisa dibeli belakangan.** Satu
   mesin order, bukan dua. Order add-on-only ber-`plan_id` **null**.
2. **Registrasi domain manual oleh super_admin.** Tidak ada integrasi registrar.
3. **Subdomain pakai HTTP-01 per-subdomain** — `DomainService` tidak berubah
   jalur penerbitannya. **Butuh wildcard A record `*.floevent.id`** (lihat
   Deploy di bawah).
4. **White-label diturunkan dari `domain_certified_at`.** Tidak ada kolom kedua.

## Harga (super_admin, `/admin/settings`)

| Key | Default | Arti |
| --- | --- | --- |
| `domain_setup_fee_amount` | 100.000 | Biaya konfigurasi, **ketiga** jenis |
| `domain_register_com_amount` | 300.000 | Harga `.com` setahun |
| `domain_register_id_amount` | 370.000 | Harga `.id` setahun |

Total = biaya konfigurasi + harga registrasi (0 untuk `own`/`subdomain`).
Default-nya di `config/domains.php`, pola `PlatformSettings`/`config/wallet.php`.

## Status: selesai

### Tahap 1 — katalog harga & aturan
- [x] `App\Support\DomainAddons` — tiga jenis, TLD yang dijual, `quote()`,
      `catalogue()`. Satu penjawab "add-on ini berapa", dibaca checkout **dan**
      preview.
- [x] `App\Support\DomainNames` — normalisasi label, `RESERVED_LABELS`,
      `hostFor()`.
- [x] `App\Support\DomainAddonRules` — aturan validasi dieja **sekali**, dipakai
      tiga pintu (checkout paket, checkout add-on, preview, fulfil admin).
- [x] Tiga key harga di `PlatformSettings::BASE_DEFINITIONS`.

### Tahap 2 — kolom & model
- [x] `2026_11_15_100000_add_domain_addon_to_event_plan_orders_table`
- [x] `2026_11_15_100001_add_subdomain_to_events_table`
- [x] `EventPlanOrder`: `hasDomainAddon()`, `isAddonOnly()`,
      `isAwaitingDomainFulfilment()`, `scopeAwaitingDomainFulfilment()`,
      `domainEvent()`, `domainFulfiller()`
- [x] `scopeUnconsumed()` + `whereNotNull('plan_id')`
- [x] `Event`: `subdomain` fillable, `usesPlatformSubdomain()`, `isWhiteLabel()`

### Tahap 3 — checkout
- [x] `checkout()` menerima add-on (satu invoice dengan paket)
- [x] `checkoutAddon()` untuk event yang sudah ada (`plan_id` null)
- [x] `applyDomainAddon()` / `attachDomain()` / `fulfilDomain()`

### Tahap 4 — klaim kredit & publikasi
- [x] `EventController::store()` mengikat add-on ke event baru
- [x] `attachPaidDomain()` di `transition()` — **publikasi** yang memasang

### Tahap 5 — white-label
- [x] `PublicEventController::index()` mengecualikan event white-label
- [x] `PublicEventResource.white_label`, `EventResource` kolom domain
- [x] Halaman publik: back-link hero + footer digerbang

### Tahap 6 — antrean super_admin
- [x] `GET admin/domain-orders`, `POST admin/domain-orders/{planOrder}/fulfil`

### Tahap 7 — frontend
- [x] `web/lib/domain.ts`, `web/lib/api/domains.ts`, tipe di `types/api.ts`
- [x] `components/domain/domain-addon-field.tsx` (dipakai dua surface)
- [x] `PlanCheckoutDialog` + add-on
- [x] `/organizer/events/{id}/domain` — status, instruksi DNS, beli belakangan
- [x] `/admin/domain-orders`
- [x] Baris add-on di `pdf/_document.blade.php`

### Tahap 8 — test & verifikasi
- [x] `tests/Feature/DomainAddonTest.php` — 19 test / 100 assertion
- [x] `php artisan test` — lihat Status verifikasi
- [x] `bunx tsc --noEmit` bersih, `bun test proxy.test.ts` 8 lulus

## Catatan implementasi

**`domain_event_id`, bukan `event_id`.** Yang kedua unique — "satu kredit, satu
event" adalah fakta database — jadi add-on untuk event yang kreditnya sudah
terpakai akan bentrok dengan order yang memegangnya. Menumpanginya juga memberi
kolom itu dua arti, bentuk nullable-pair yang codebase ini terus tolak.

**Add-on masuk `amount` SEBELUM `resolvePayment()`,** invarian yang sama dengan
diskon: `PaymentFeeCalculator` menghitung fee persentase dari `$amount` dan
`platformDestination()` bercabang pada `$amount <= 0`.

**Diskon TIDAK memotong add-on.** Kode dijual terhadap **paket**
(`discount_code_plan` plan-keyed, `resolve()` menerima `Plan`), dan
`paidTowardsPlan()` memakai `amount` untuk menghitung selisih upgrade — diskon
yang menggerus add-on akan mengkreditkan uang domain ke harga paket.

**Pemasangan terjadi saat PUBLIKASI, bukan saat event dibuat.** Event baru selalu
`draft`, `DomainService::assign()` menolak draf (halaman draf 404 publik, dan
minta sertifikat untuk halaman yang tak bisa dibuka membakar kuota Let's Encrypt
yang dipakai seluruh platform). `attachPaidDomain()` dipanggil di **tiap**
transisi, bukan hanya publikasi pertama — idempoten, dan itu yang membuat event
yang dibatalkan lalu diaktifkan kembali mendapatkan domainnya tanpa tangan
manusia.

**Subdomain menulis DUA kolom.** `custom_domain` = hostname penuh (yang dibaca
generator nginx, certbot, dan peta `proxy.ts`), `subdomain` = labelnya. Label
itulah yang membedakan "nama ini kami terbitkan" dari "organizer membawa
sendiri" — dan bedanya load-bearing: melepas subdomain mengembalikan label ke
pool, melepas domain pelanggan tidak boleh berpura-pura kami pernah memegangnya.
Cek turunan (`str_ends_with`) akan jadi pembaca kedua, dan pecah begitu ada
organizer yang sah memiliki nama di bawah domain yang kami layani juga.

**`verifyDns()` tidak dicabangkan untuk subdomain.** Wildcard `*.floevent.id`
membuat pemeriksaan yang sudah ada benar apa adanya untuk label apa pun — dan
gagal untuk semuanya selama wildcard belum ada, yang justru hasil yang
diinginkan. Pengecualian `kind === 'subdomain'` akan langsung ke certbot dan
membakar kuota kegagalan bersama atas zona yang cuma lupa dikonfigurasi.

**Ketersediaan nama memeriksa order, bukan cuma event.** Nama yang sudah dibayar
tapi belum diregistrasi belum ada di `custom_domain` mana pun; tanpa cek itu dua
organizer bisa sama-sama membayar `kontes.id` dan satu di antaranya kalah
setelah membayar.

## Deploy ke VPS

1. **Wildcard DNS wajib** — tambahkan A record `*.floevent.id` → IP VPS di
   registrar. Tanpa ini `verifyDns()` menolak **setiap** subdomain dan tidak ada
   satu pun sertifikat yang terbit. Ini satu-satunya langkah di luar repo.
2. `php artisan migrate` (dua migrasi, keduanya aditif).
3. Rebuild `api` + `web` seperti biasa. **Tidak** ada dependensi baru: certbot
   dan Imagick sudah ada di image.
4. Harga bisa langsung disetel di `/admin/settings` — defaultnya 100k/300k/370k
   sesuai permintaan, jadi nol baris `platform_settings` sudah benar.

## Status verifikasi (2026-11-15)

- `php artisan test --filter=DomainAddonTest` — **19 lulus / 100 assertion**
- `php artisan test` (penuh) — lihat catatan di bawah
- `bunx tsc --noEmit` — bersih
- `bun test proxy.test.ts` — 8 lulus

Belum dijalankan — butuh tangan manusia:

- [ ] Uji lokal subdomain: `127.0.0.1 liga-a.floevent.test` di `/etc/hosts`
      (butuh sudo), satu event ber-`subdomain`, lalu cek root & `/tickets`
- [ ] Uji di VPS sesudah wildcard DNS terpasang
- [ ] FAQ & footnote `pricing.tsx` belum menyebut add-on domain — butuh migrasi
      copy bergaya `2026_08_02_110001` (match teks lama supaya suntingan
      super_admin tidak tertimpa), layak saat fiturnya dipasarkan
