# Pindah kategori pendaftaran fun run (`race_entry_transfers`)

Pelari yang salah pilih kategori — atau berubah pikiran dari 5K ke 10K — memindahkan
pendaftarannya sendiri di halaman bukti. Kategori lebih mahal: bayar selisihnya. Lebih
murah: selisihnya dikembalikan lewat antrean `refund_payouts` yang sudah ada.

## Keputusan yang sudah final

| Pertanyaan | Jawaban |
| --- | --- |
| Siapa pintunya | **Pelari**, di `/race-entries/{id}` (publik, id = kredensial — sama dengan `proof`/`refund-request`) |
| Harga tujuan dibaca dari | **Periode entry** (`race_entries.race_period_id`), bukan periode sekarang |
| Selisih dihitung dari | **Yang benar-benar dibayar** (`price + Σ transfer.amount`), diskon tidak terbawa |
| Turun kategori | Debit dompet + satu baris `refund_payouts` (`source_type: 'race_entry_transfer'`) |
| Baris entry | **Diubah di tempat** (`race_category_id` berpindah) — bukan baris entry baru |
| Harga di `race_entries.price` | **TIDAK pernah ditulis ulang** — kwitansi yang sudah terbit tidak berubah isinya |

## Invarian

> **Invarian: `race_entries.price` tidak disentuh, dan tabel transfer-lah yang menjawab
> "sudah bayar berapa".** `ParticipantDocumentService` merender invoice & kwitansi
> **on-demand dari barisnya**, jadi menulis ulang `price` mengubah dokumen yang sudah
> diunduh orang — invarian snapshot yang sudah dieja untuk `withdrawals`, `period_name`,
> dan `payment_method`. Jadi `RaceEntryTransfer::paidTowards($entry)` = `price + Σ amount`
> atas transfer lunas, cermin `EventPlanOrder::paidTowardsPlan()`. Memakai `price` sendiri
> lolos setiap uji satu langkah lalu menagih kelebihan begitu ada yang pindah dua kali.
> Ujilah dengan **membandingkan** rantai 5K→10K→21K terhadap pendaftaran langsung 21K:
> totalnya wajib sama.

> **Invarian: harga tujuan dibaca dari periode ENTRY, bukan periode sekarang.**
> `RacePricing::priceFor($target, $entry->period)` — pelari yang beli di Early Bird pindah
> dengan harga Early Bird. Periode null (baris warisan, `race_period_id` nullOnDelete)
> **menolak**, bukan jatuh ke periode sekarang: itu mengarang angka yang tidak ada pihak
> yang bisa menunjukkan di mana disetujui, bacaan yang sama dengan celah antar periode.
> Ujilah dengan **membandingkan** transfer di dalam periode kedua terhadap harga periode
> pertama; assert "harga tersimpan" sendirian lolos walau periode sekarang yang dibaca.

> **Invarian: kuota pindah dalam dua langkah, dan arahnya disengaja.** Transfer dibuat →
> `increment` kategori **tujuan** saja (pelari memegang tempat lunasnya + menahan tempat
> yang sedang ia bayar). Transfer lunas → `decrement` kategori **asal**. Transfer
> hangus/dibatalkan → `decrement` **tujuan**. Melepas asal lebih awal berarti transfer yang
> gagal meninggalkan pelari tanpa tempat yang sudah ia bayar — tempatnya bisa sudah diambil
> orang. Ujilah dengan **membandingkan** `taken` kedua kategori di ketiga titik; assert
> keadaan akhir sendirian lolos di build yang sempat melepas asal lebih awal.

> **Invarian: `bib_number` dibuang saat pindah.** Nomornya ber-prefix slug kategori
> (`5K-003`), jadi pelari yang pindah ke 10K membawa nomor yang mengaku kategori lain.
> `bib_number`, `bib_png_key`, `bib_sent_at` di-null-kan supaya `BibService::assignNumbers()`
> menomori ulang di kategori baru. `qr_code` **tetap** — ia kredensial orangnya dan check-in
> membaca entry, bukan kategori. Ujilah dengan **membandingkan** keduanya sesudah transfer.

> **Invarian: "sedang pindah" DITURUNKAN, bukan nilai status keenam di `race_entries`.**
> Invarian `awaiting_verification`/`refund_requested_at` lagi, dan sebabnya sama persis:
> tiga pembaca men-guard `in_array($entry->status, ['paid','cancelled','refunded'])`.
> `RaceEntry::hasOpenTransfer()` satu-satunya penjawabnya.

> **Invarian: selisih masuk `amount` SEBELUM fee dihitung.** `PaymentFeeCalculator` mengambil
> persentase dari `$amount` dan `PaymentRails::destinationFor()` bercabang pada `$amount <= 0`.
> Ujilah di channel persentase (`ewallet`); di `va` fee-nya flat.

## Tahapan

### Backend
- [x] Migrasi `create_race_entry_transfers_table`
- [x] Model `RaceEntryTransfer` (+ `HasManualPayment`, `paidTowards()`)
- [x] `RaceEntry::hasOpenTransfer()` / `canTransferCategory()` / relasi `transfers`
- [x] `RaceTransferService` — satu-satunya yang boleh menggeser kategori & kuota
- [x] `RefundPayout::SOURCE_LABELS['race_entry_transfer']`
- [x] `WalletService::creditRaceEntryTransfer()` + `debitRaceEntryTransfer()` + `EARNING_CATEGORIES`
- [x] `RefundService::refundRaceEntryTransfer()` (turun kategori)
- [x] `PublicRaceController::transferOptions()` + `transfer()` + `transferProof()`
- [x] `MidtransWebhookController` arm `RXF-`
- [x] `PaymentVerificationController` rail keempat (acc bukti transfer)
- [x] `race-transfers:expire-manual` + jadwal hourly
- [x] `config/billing.php` prefix `transfer_*`
- [x] Dokumen PDF (reuse `ParticipantDocumentService`)
- [x] Routes
- [x] `RaceEntryResource` publikasi `can_transfer_category` + `open_transfer`

- [x] `RaceEntryResource`: `can_transfer_category`, `open_transfer`, `transfer_history`
- [x] Dokumen top-up punya rute sendiri (`race-transfers/{id}/invoice|receipt`)

### Frontend
- [x] `types/api.ts` (`RaceTransfer*`, `open_transfer`, `transfer_history`)
- [x] `lib/api/race.ts` + subject `race-transfer` di `participant-documents.ts`
- [x] `components/race/category-transfer-panel.tsx`
- [x] Halaman bukti: panel, `ManualTransferPanel` untuk top-up, riwayat + dokumennya

### Test
- [x] `RaceCategoryTransferTest` (19) — rantai harga, periode entry, kuota tiga titik,
      bib vs QR, dompet dua arah, fee persentase, lima guard, dokumen, `org.admin`

## Yang dinyatakan terbuka

- **Organizer tidak punya pintu pindah kategori.** Keputusan desainnya pelari-only;
  organizer yang perlu memindahkan seseorang memakai `cancel` + entri ulang, atau
  menunggu pintu kedua kalau nanti diminta (pola `approve()`/`issue()` di
  `RaceRefundController` adalah bentuknya — satu service, dua pemanggil).
- **Diskon tidak pernah dihitung ulang.** Pelari yang beli dengan kode lalu pindah
  ditagih selisih terhadap uang yang **benar-benar** masuk, jadi total belanjanya
  mendarat di harga katalog kategori tujuan. Sama dengan `paidTowardsPlan()`; tidak
  ada kolom `list_amount`, dan `event_discount_codes` tidak dibaca dari pintu ini.
- **`teams` tidak punya padanan ini.** Pindah kategori tim menyentuh roster, ukuran
  skuad, dan undian — bukan satu harga. Menyalin pola ini ke sana akan jadi penulis
  kedua atas `event_categories.participant_type` yang justru dikunci.
- **Tiket penonton juga tidak**, alasan yang sama dengan diskon peserta: satu basket
  berisi N tiket, jadi "pindah" dan "pindah berapa tiket" dua pertanyaan.
