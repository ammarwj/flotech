# Antrean transfer balik refund (`refund_payouts`)

Status: **SELESAI** — enam tahap, 1139 tes lolos, nol regresi
Dibuat & diselesaikan: 2026-11-07

## Masalah

Organizer approve refund → saldo dompetnya **langsung** berkurang, lalu tidak ada
apa pun yang terjadi. Tiga gap yang terverifikasi di kode:

1. **Nol notifikasi refund.** `app/Notifications/` punya 12 entri (`WithdrawalCompleted`,
   `PlanOrderIdle`, …); tidak satu pun refund. Super_admin tidak pernah tahu ada utang
   transfer.
2. **Race entry tidak terlihat di `/admin/payments`.** `Admin\RefundController::index()`
   hanya menyusun `TicketOrder` + `Team`. Refund fun run tidak bisa ditemukan walau
   super_admin sengaja mencari.
3. **Tidak ada kolom yang mencatat transfer baliknya pernah terjadi.** Bandingkan
   `withdrawals` yang punya `proof_url` + `transfer_reference` + `completed_at`.

## Alur yang disetujui

```
pelari ajukan → organizer approve (saldo turun, kuota kembali)
  → baris refund_payouts status=pending, super_admin diberi tahu
  → super_admin transfer ke REKENING ORGANIZER + upload bukti → status=completed
  → organizer diberi tahu, terima uang
  → organizer teruskan ke pelari + upload bukti → forwarded_at terisi
  → pelari melihatnya di halaman buktinya
```

Rekening tujuan = `bank_accounts` organizer yang **sudah ada**. Platform tidak
pernah mengumpulkan rekening peserta; organizer-lah yang punya hubungan dengan
pesertanya.

## Keputusan yang sudah diambil

### Jumlahnya = `$credit->amount`, dibaca dari baris ledger

Terverifikasi: `race_entries.platform_fee` **default 0** dan komentar migrasinya
menyatakan kenapa — fee dibayar pembeli **di atas** harga, bukan dipotong dari
organizer.

```
price         99.000  ← harga kategori, bersih; ini yang dikredit
service_fee    2.000  ← pembeli bayar
gateway_fee    4.000  ← pembeli bayar
gross_amount 105.000  → Midtrans
```

`creditRaceEntry()` membaca `gross = price`, `fee = platform_fee` = 0 → kredit 99.000.
Ketiga angka (yang turun dari dompet / yang platform transfer / yang pelari terima)
**identik**. Ketiga rail sama: `creditTicketOrder()`/`creditRegistration()` juga
`gross − fee` dengan fee 0.

> **Invarian: jumlahnya dibaca dari baris ledger, tidak pernah direkonstruksi.**
> `price + service_fee` atau `gross_amount − fee` adalah aritmetika kedua yang bisa
> menyimpang dari apa yang benar-benar turun dari dompet — terutama saat ada diskon
> (`price` sudah net) atau saat fee di `/admin/settings` diubah sesudah pembayaran.
> `refund_payouts.amount` = `$reversal->amount`, nilai yang `reverseCredit()`
> kembalikan. Ujilah dengan **membandingkan** entry berdiskon dan entry penuh di
> kategori yang sama: `refund_payouts.amount` wajib sama dengan debit ledger-nya,
> bukan dengan harga kategori.

### Tabel sendiri, bukan kolom di tiga tabel

`source_type`/`source_id` (pola `wallet_transactions`). Tiga rail refund sudah ada;
kolom yang disalin tiga kali adalah bentuk nullable-pair yang invarian
`official_match_stats` terus tolak, dan antreannya akan jadi union tiga query.

### Transfer ini TIDAK menyentuh dompet

> **Invarian: `refund_payouts` wajib melewati `WalletService` sepenuhnya.** Saldo
> organizer baru saja **turun** sebesar kredit yang dibalik. Menulis transfer baliknya
> sebagai kredit dompet menaikkannya lagi dan membatalkan refund-nya secara efektif —
> organizer dikreditkan atas uang yang justru sedang dikembalikan. Alasan yang sama
> sudah ditulis untuk transfer manual pembeli: uang yang tidak lewat platform tidak
> boleh muncul di ledger. Ujilah dengan **membandingkan** saldo sebelum refund,
> sesudah refund, dan sesudah `complete()` — dua angka terakhir wajib **sama**;
> assert "saldo turun sesudah refund" sendirian lolos walau `complete()` mengkreditkan
> balik.

### Status: `pending | completed`, tanpa `processing`

Beda dari `withdrawals` yang punya `processing`: itu antrean pencairan dengan volume
dan bisa digarap sebagian. Ini refund — satu transfer, satu bukti. Nilai ketiga akan
jadi keadaan yang tak seorang pun menuliskan artinya.

### `forwarded_at` + `forwarded_proof_url` di baris yang sama

Bukan tabel kedua: ia atribut dari satu transfer yang sama, dan tabel kedua akan
memaksa join untuk menjawab "sudah sampai ke pelari?" yang halaman bukti pelari
tanyakan tiap kali dibuka.

## Tahapan

### Tahap 1 — skema & model ✅
- [x] Migrasi `create_refund_payouts_table` — sudah jalan di dev
- [x] Unique `(source_type, source_id)` — satu refund, satu utang transfer
- [x] Model `RefundPayout` + relasi + scope `awaitingTransfer()`/`awaitingForwarding()`
- [x] `Organization::refundPayouts()`

### Tahap 2 — penulisan ✅
- [x] `RefundPayoutService::record()` — satu-satunya penulis baris pending
- [x] `RefundService::reverse()` sekarang **mengembalikan** `?WalletTransaction`;
      itu yang membuat `amount` bisa dibaca, bukan direkonstruksi
- [x] Ketiga rail memanggil `record()` di dalam transaksi yang sudah ada
- [x] Idempoten lewat unique index (pola `WalletService::record()`)
- [x] `complete()` (super_admin) + `markForwarded()` (organizer)
- [x] Null reversal → **tidak** ada baris (gratis / manual / kredit masih pending)

### Tahap 3 — endpoint ✅
- [x] `GET admin/refund-payouts` (pending oldest-first + completed di payload sama)
- [x] `PATCH admin/refund-payouts/{payout}/complete` (`proof_url` wajib)
- [x] `GET {org}/refund-payouts` + `PATCH .../{payout}/forward` di `org.admin`
- [x] `RefundPayoutResource` — menerbitkan `awaiting_transfer`/`awaiting_forwarding`

### Tahap 4 — notifikasi ✅
- [x] `RefundAwaitingTransfer` → super_admin, dikirim `DB::afterCommit`
- [x] `RefundTransferCompleted` → organizer owner
- [x] Dua template Blade
- [x] Keduanya `ShouldQueue` — **worker butuh rebuild image**, bukan restart

### Tahap 5 — frontend ✅
- [x] `lib/api/refund-payouts.ts` + tipe `RefundPayout` di `web/types/api.ts`
- [x] `/admin/refund-payouts` — dua tab, hitungan di tab pending, peringatan
      rekening kosong, dan **status hop dua read-only** (itu yang menjawab admin
      saat peserta menagih)
- [x] `/organizer/refund-payouts` — banner jumlah yang perlu diteruskan, tombol
      hanya saat `awaiting_forwarding` (**disembunyikan**, bukan disabled — pola
      `CANCELLABLE` di halaman race)
- [x] Entri nav di kedua menu
- [x] `components/shared/refund-transfer-dialog.tsx` — **satu** dialog untuk dua
      hop, dipilih prop `hop`; panel rekening hanya untuk `platform` (rekening
      peserta tidak pernah kami simpan)
- [x] `RaceEntry.refund_transfer` + `RefundRequestPanel` tiga kalimat per tahap;
      kalimat generik tetap fallback saat tidak ada utang transfer
- [x] `tsc --noEmit` bersih

### Tahap 6 — tes ✅ (backend)

### Tahap 6 — tes ✅ (backend)
- [x] `RefundPayoutTest` — **14 lolos, 53 assertion**
- [x] Saldo tiga titik: sebelum / sesudah refund / sesudah complete (dua terakhir sama)
- [x] `amount` = debit ledger, bukan harga kategori (entry diskon vs penuh)
- [x] Manual vs gateway → hanya gateway menulis baris
- [x] Kredit pending vs released → hanya released menulis baris
- [x] Tiga aktor satu payload untuk `forward` (owner/operator/org lain → 200/403/403)
- [x] Organizer tidak bisa menulis separuh platform (`status`/`amount`/`proof_url`)
- [x] Idempotensi `record()` + `complete()` kedua ditolak
- [x] Snapshot rekening tahan edit
- [x] Dua hop, dua notifikasi
- [x] Regresi: suite `Refund|Wallet|Withdrawal` **91 lolos**, nol regresi

## Deploy

1. `php artisan migrate` — satu migrasi, `create_refund_payouts_table`
2. **Rebuild image `worker` + `scheduler`**, bukan restart. Dua notifikasi baru
   ber-`ShouldQueue`; container lama akan gagal `__PHP_Incomplete_Class`. Sudah
   dijalankan di dev.
3. Tidak ada seeder, tidak ada backfill — nol baris adalah keadaan produksi yang
   benar. Refund yang sudah terbit sebelum tabel ini ada sudah ditangani manual
   atau tidak; mengarang baris untuknya akan menaruh utang transfer palsu di
   depan super_admin tanpa cara membedakan mana yang sudah dibayar.

## Catatan

- Gap yang sebelumnya terbuka ("rantai berhenti di organizer") **ditutup** di tahap 5
  oleh `forwarded_at` + bukti, dan pelari melihatnya di halaman buktinya.
- `wallet:audit` nanti layak diajari memeriksa pasangan: tiap debit
  `category: 'refund'` wajib punya satu `refund_payouts`. **Belum dikerjakan** —
  pekerjaan terpisah di command itu, dan invariannya sudah ditegakkan di sisi
  tulis (`record()` dipanggil di transaksi yang sama).
- **`/admin/payments` masih tidak memuat race entry.** Itu gap nomor 2 dari tiga
  yang ditemukan, dan tertutup secara praktis oleh `/admin/refund-payouts` (tiap
  refund race entry muncul di sana). Daftar pembayaran-nya sendiri masih dua rail;
  menambahkannya = satu `->map()` ketiga di `Admin\RefundController::index()`.
- CLAUDE.md belum ditambahi section untuk pola ini. Layak ditulis saat ada
  perubahan kedua yang menyentuhnya.
