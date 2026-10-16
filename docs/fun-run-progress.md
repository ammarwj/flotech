# Fun Run — progress

Cabang ke-11, `scoring: 'none'`. Pelari mendaftar sendiri, pilih kategori
(mahasiswa/umum, dinamis), bayar, tunjukkan kwitansi di hari H. Harga beda per
periode penjualan (early bird / pre sale / last).

Rencana penuh: `~/.claude/plans/curried-tickling-bubble.md`

## Fase 1 — pendaftaran + kwitansi

### Migrasi
- [x] `seed_fun_run_sport` — baris cabang disemai di migrasi, bukan SportSeeder
- [x] `create_race_periods` — jendela per event, `starts_at`/`ends_at` NOT NULL
- [x] `create_race_categories` — FK `event_id` saja, tanpa kolom `price`
- [x] `create_race_category_prices` — unique (kategori, periode); baris absen = tidak dijual
- [x] `create_race_entries` — set `HasManualPayment` lengkap + 4 kolom fase 2
- [x] `create_race_entry_documents` — tabel sendiri (`registration_documents.team_id` NOT NULL)
- [x] `add_race_entry_service_fee_setting`

### Gate `scoring: 'none'`
- [x] `Sport::SCORING` + `'none'`
- [x] `Catalog::scoringOf()` + `Catalog::isCompetitive()`
- [x] `MatchClockRules:79` negatif → positif
- [x] `SquadRules:94` negatif → positif (ekspresi literal identik)
- [x] `web/types/api.ts` melebar + tipe `RacePeriod`/`RaceCategory`/`RaceEntry`
- [x] `<option value="none">` di `/admin/sports`
- [x] hapus `use-catalog.ts` `isSetBasedSport()` (nol pemanggil)

### Backend
- [x] Model: `RacePeriod`, `RaceCategory`, `RaceCategoryPrice`, `RaceEntry`, `RaceEntryDocument` + relasi `Event`
- [x] `App\Support\RacePricing` — satu penjawab harga (+ `RacePricingTest`, 10 uji, diuji-mutasi)
- [x] `RaceEntryService` — bentuk `TicketService`; `hold()` TIDAK mengisi `qr_code`
- [x] `PaymentFeeCalculator::AUDIENCE_RACE_ENTRY` + config + PlatformSettings
- [x] `WalletService::creditRaceEntry()` + **daftar `total_earned` baris 307**
- [x] `RefundService::refundRaceEntry()`
- [x] arm webhook `RUN-` (catatan: `match` menilai `default` paling akhir apa pun posisinya — bahayanya arm yang **hilang**, bukan posisinya; sudah diverifikasi)
- [x] `race-entries:expire-manual` (hourly)
- [x] `ParticipantDocumentService` → union tiga + prefix `INV-RUN`/`KW-RUN`
- [x] Controller: `PublicRaceController`, `RaceCategoryController`, `RacePeriodController`, `RaceEntryController`
- [x] rail ketiga di `PaymentVerificationController` (`org.admin`)
- [x] `RaceEntriesExport` (+ `METHOD_LABELS` dipindah ke `HasManualPayment`)
- [x] kunci `sport_type` saat event sudah punya peserta (dua arah)
- [x] `MediaCleanupService::raceEntryUrls()`

### Frontend
- [x] `web/lib/race.ts` + `lib/api/race.ts`
- [x] `(public)/[orgSlug]/[eventSlug]/race/page.tsx`
- [x] `(public)/race-entries/[entryId]/page.tsx`
- [x] `organizer/events/[id]/race/page.tsx` (+ `RaceCategoryForm`, `RacePeriodsDialog`)
- [x] gating kartu event organizer + `RaceRouteGuard` di 6 rute
- [x] tab halaman publik + fallback `activeTab` (fun run = Info saja)
- [x] `event-form.tsx` blok kategori → notice

### Test
- [x] `RacePricingTest` (10), `FunRunGatingTest` (5), `RaceEntryTest` (16), `RaceEntryWebhookTest` (4) — semuanya diuji-mutasi
- [x] regresi `CatalogTest` (hitungan cabang 9→10 + assert `scoring`)
- [x] suite penuh hijau: **964 lolos**
- [x] verifikasi di app sungguhan (end to end lewat API + webhook):
      buat event fun run → 3 periode (tolak tumpang tindih) → 2 kategori
      dengan matriks harga → daftar gateway → settle via webhook `RUN-`
      (`KW-RUN/2026/10/0001`, `qr_code` NULL, `total_earned` naik) →
      matikan gateway → daftar manual → unggah bukti → antrean organizer →
      acc → **bandingkan**: gateway 1 baris ledger, manual 0 →
      `race-entries:expire-manual` (yang terlantar hangus, yang ada buktinya
      tidak, kuota kembali)
- [ ] e2e Playwright `race-registration.spec.ts` (opsional; alurnya sudah
      diverifikasi manual di atas)

## Catatan dari verifikasi live

Dua hal yang tidak terlihat saat membaca kode, hanya saat menjalankannya:

1. **`POST events` mewajibkan `categories`** (`EventCategoryRules::make('required')`),
   jadi event fun run tidak bisa dibuat sama sekali. Diperbaiki di
   `StoreEventRequest` (membaca `Catalog::scoringOf($this->input('sport_type'))`),
   dan `min:1` dipindah supaya hanya berlaku saat `required`.
2. **`syncCategories()` perlu early-return** untuk `scoring === 'none'` — tanpa
   itu sapuan delete di ujung method berjalan dengan `$keep` kosong.

Keduanya sekarang punya test pembanding di `RaceEntryTest`.

Rapi-rapi yang ikut: `METHOD_LABELS` dipindah dari `TicketOrder` ke
`HasManualPayment` (satu-satunya tempat yang tahu arti ketiga nilai rail itu),
jadi export lomba tidak perlu mengimpor model tiket.

## Fase 2 — selesai (nol migrasi, seperti rencana)

Scan check-in di meja registrasi. Empat kolom yang disiapkan fase 1 akhirnya
punya pembaca — kecuali `bib_number`, yang kolomnya ada tapi penulisnya
ditunda (lihat bawah).

### Backend
- [x] `RaceEntryService::newQrCode()` — `RUN-` + ULID, bentuk `TIX-`
- [x] QR dicetak di `markPaid()`, **tidak pernah** di `hold()`; `??` menahan
      webhook ulang dari mencetak kode kedua. Entry **gratis** tetap dapat QR
      (beda dari kwitansi: `price > 0` soal tagihan, bukan soal garis start)
- [x] `ensureQrCode()` — entry lunas warisan fase 1 dapat kode saat dibaca.
      UPDATE berkondisi (`whereNull`), **bukan** `lockForUpdate`: halaman bukti
      mem-poll tiap 4 detik dan bisa terbuka di dua perangkat
- [x] `RaceEntryService::checkIn()` — satu-satunya penulis tiga kolom check-in
- [x] `RaceScanController` — `checkIn`/`report`/`checkins`, grup `tenant`
- [x] tiga rute: `race-scan`, `race-report`, `race-checkins`
- [x] `qr_code`/`bib_number`/`checked_in_at` terbit di `RaceEntryResource`
      (`qr_code` lewat `when()` — absen, bukan null, saat belum lunas)

### Frontend
- [x] `lib/hooks/use-qr-scanner.ts` — logika kamera diekstrak; **halaman scan
      tiket ikut memakainya** (315 → 287 baris), jadi debounce & busy-ref hidup
      sekali
- [x] `organizer/events/[id]/race-scan/page.tsx` — kamera, hasil, input manual,
      hitungan "X dari Y sudah datang", log 10 terakhir
- [x] QR + badge check-in di halaman bukti pelari
- [x] tipe `RaceScanResult` **sendiri** (4 hasil, tanpa `wrong_day`)
- [x] guard rute dua arah: `race-scan` menolak event pertandingan, `scan`
      (tiket) menolak event lomba

### Test
- [x] `RaceCheckinTest` — 14 uji, semuanya pembanding
- [x] tiga uji mutasi: QR dicetak di `hold()` (2 merah), check-in kedua
      diterima (1 merah), dan `whereNull` dihapus
- [x] suite penuh: **978 lolos**
- [x] verifikasi di app sungguhan atas data warisan fase 1: dua entry lunas
      tanpa QR → dapat kode saat dibaca, **stabil** di pembacaan kedua; entry
      `expired` tidak dapat apa pun; scan #1 valid → #2 `used`; kode tiket di
      meja lomba `invalid`; hitungan 0→1; guard 404/403 dua arah

### Catatan: satu uji mutasi yang awalnya lolos

`whereNull('qr_code')` dihapus → **14 test tetap hijau**. Sebabnya
`ensureQrCode()` keluar lebih awal saat kode sudah ada, jadi pembacaan kedua
yang berurutan tidak pernah mencapai UPDATE-nya. Guard itu melindungi dari
pembaca **bersamaan**, dan tidak ada test yang mensimulasikannya. Ditambahkan
`test_two_concurrent_readers_mint_one_code` (dua panggilan dari instance basi
yang sama) — mutasinya sekarang merah.

### `bib_number`: kolomnya ada, penulisnya belum

Sengaja ditunda. Dua bentuk yang masuk akal — nomor urut otomatis saat lunas,
atau diketik organizer per gelombang — dan keduanya tidak bisa diputuskan tanpa
tahu bagaimana organizer sungguhan membagi race pack. Payload scan dan halaman
bukti sudah menampilkannya begitu ada isinya, jadi menambah penulisnya nanti
tidak menyentuh UI.
