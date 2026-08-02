# Catatan rilis — billing per-event

Migrasi dari langganan bulanan org-level ke **pembelian paket sekali bayar per event**.
Rincian pengerjaan ada di `per-event-billing-progress.md`; file ini isinya yang perlu
diketahui saat deploy dan yang perlu dikomunikasikan ke user.

## Perubahan perilaku yang bisa mengagetkan user

1. **Operator tidak bisa lagi membuat event.** `POST /organizations/{org}/events` pindah ke
   belakang middleware `org.admin`. Alasannya membuat event kini **membelanjakan uang** —
   ia mengklaim satu kredit paket yang sudah dibayar. Operator (petugas scan tiket) tetap
   bisa melakukan semua yang lain; yang hilang cuma pintu ini. Sebelumnya `tenant` saja
   sudah cukup. Organizer yang selama ini menyerahkan pembuatan event ke operator harus
   memindahkan orang itu ke role `admin`.

2. **Paket menempel di event, bukan di organisasi.** Tidak ada lagi "paket organisasi",
   tidak ada tanggal kedaluwarsa, tidak ada perpanjangan. Alurnya: beli paket → dapat
   kredit → kredit dibelanjakan saat membuat event → entitlement event itu terkunci di
   paket tersebut selamanya. Dua event milik organizer yang sama boleh berbeda paket.

3. **Cap peserta melonggar.** `max_teams_per_event` (32/128/unlimited untuk seluruh event)
   diganti `max_teams_per_category` dengan angka yang sama. Event dengan 3 kategori yang
   dulu dibatasi 32 tim **total** kini boleh 32 tim **per kategori**. Semua event lama
   di-backfill ke Professional (unlimited), jadi tidak ada yang kehilangan apa pun — tapi
   kuotanya memang lebih besar dari sebelumnya.

4. **Batas jumlah event dihapus.** `max_active_events` dipensiunkan. Organizer boleh punya
   berapa pun event; yang membatasi sekarang jumlah paket yang dibeli.

5. **Halaman & rute yang pindah** (rute lama di-redirect, bukan 404):
   - `/organizer/upgrade` → `/organizer/plans` (beli paket)
   - `/organizer/subscription` → `/organizer/billing` (kredit siap dipakai + riwayat)
   - `/admin/subscriptions` → `/admin/plan-orders`
   - API `…/subscriptions*` → `…/plan-orders*`
   - Onboarding menyusut dari 3 langkah jadi 1 (cuma nama organisasi); pembelian paket
     terjadi belakangan, saat event pertama dibuat.

6. **Harga sekali bayar per event**: Starter Rp150.000 · Pro Rp350.000 · Professional
   Rp800.000. Tidak ada toggle bulanan/tahunan lagi. Paket `basic` dipensiunkan
   (`is_active`/`is_public` = false) — barisnya tidak dihapus karena order lama menunjuknya.

7. **Fee platform jadi satu key.** `ticket_fee_percent` + `registration_fee_percent`
   dilebur jadi `platform_fee_percent` (3% / 2% / 1%). Nilainya sudah identik di ketiga
   paket sebelum peleburan, jadi tidak ada perubahan tarif. **Fee diambil dari paket
   event**, bukan paket organisasi, dan tetap di-snapshot per order.

8. **CTA "Hubungi Sales" dilepas** dari kartu Professional — ketiga paket kini self-serve.

## Fitur baru

- **Upgrade paket, self-serve.** Paket bisa dinaikkan kapan saja — baik kredit yang belum
  dipakai maupun event yang sudah berjalan — dan organizer **hanya membayar selisihnya**.
  Starter yang naik ke Pro menambah Rp 200.000, jadi totalnya sama persis dengan langsung
  membeli Pro; naik dua kali pun tetap berhenti di harga katalog paket tertinggi yang
  dicapai. Tombolnya di `/organizer/billing`, dan hint kapasitas di form event menautkan
  ke sana.
  - **Downgrade sengaja tidak ada.** Paket target wajib memberi ≥ setiap fitur paket
    sekarang, jadi fitur tidak pernah bisa dicabut dari event yang sedang memakainya.
    Aturan yang sama itu pula yang menolak "upgrade" ke paket lebih mahal yang kebetulan
    kehilangan satu fitur.
  - Tagihan upgrade punya invoice & kwitansinya sendiri berisi **selisihnya**; invoice
    lama tidak berubah isinya. Jalur transfer manual tetap berlaku saat gateway mati.
  - Endpoint `POST admin/events/{event}/reassign-plan` **tetap ada** dan tidak berubah —
    itu untuk menukar dua kredit yang sama-sama sudah dibayar penuh, bukan untuk naik
    paket.

- **Export Excel & PDF sungguhan** (`export_data`, Pro ke atas): pendaftaran, pembeli
  tiket, klasemen, leaderboard. Menggantikan unduhan CSV yang lama.
- **Katup super_admin `POST admin/events/{event}/reassign-plan`** untuk memindahkan event
  ke paket lain (kasus salah beli). Endpoint + guard sudah ada dan teruji; **tombolnya di
  `/admin/plan-orders` belum dibuat** — sementara ini lewat API. Untuk *naik* paket,
  organizer sekarang tidak perlu ini lagi.
- Key baru: `online_registration`, `max_categories`, `sponsor_logos`, `organizer_profile`,
  `event_gallery`, `max_gallery_photos`.

## Urutan deploy

1. `php artisan migrate` — jalankan **maju**, jangan pernah `migrate:fresh`/`refresh`/
   `db:wipe` terhadap database produksi. Migrasi terakhir (`drop_plan_from_organizations`)
   sengaja jalan paling belakang supaya backfill masih bisa membaca kolom lamanya.
2. Backfill jalan otomatis lewat migrasi `100004`. Kalau perlu diulang manual:
   `php artisan events:backfill-plan --dry-run` lalu tanpa flag. Idempoten.
3. Verifikasi: tidak ada event tanpa `plan_id` (command ini gagal sendiri kalau ada), dan
   `/organizer/billing` menampilkan riwayat order lama.
4. Scheduler: `plan-orders:expire-manual` menggantikan `subscriptions:expire-manual`.
5. Migrasi upgrade paket ikut di sini: `add_upgrade_of_to_event_plan_orders_table` (satu
   kolom) dan `answer_the_upgrade_faq_now_that_upgrades_exist` (FAQ landing yang tadinya
   menjawab "tidak bisa").

Event hasil backfill diberi paket **Professional** beserta order historisnya
(`invoice_number`/`receipt_number` **null** — tidak ada uang yang berpindah, jadi tidak
ada dokumen yang boleh terbit). Efeknya semua event lama tetap punya semua fitur yang
sebelumnya mereka nikmati.

## Utang yang sengaja ditinggalkan

Lihat bagian "Utang yang sengaja ditinggalkan" di `per-event-billing-progress.md`.
