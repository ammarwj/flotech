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

## Fase 2 — ditunda
Scan QR + nomor BIB. Kode saja, nol migrasi.
