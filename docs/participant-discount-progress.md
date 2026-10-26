# Kode diskon pendaftaran peserta — progress

Fitur: potongan harga (persentase / nominal) yang diketik **peserta** saat mendaftar.
Dibuat organizer per event di `/organizer/events/{id}/discount-codes`, dipakai di form
pendaftaran tim & form fun run.

Keputusan: **milik event** (tabel `event_discount_codes`, bukan memperluas
`discount_codes` yang plan-keyed milik super_admin), dua rail saja (**tiket penonton
tidak ikut**), potongan **ditanggung organizer** (harga net = gross dompet), tanpa
batasan kategori, kuota 1× dikunci pada **akun peserta**. Pola lengkap
didokumentasikan di `CLAUDE.md` (section "Kode diskon pendaftaran peserta").

## Backend

- [x] `Models\Concerns\HasDiscountFormula` — `TYPES`, `normalize()`, `discountFor()`
      dipindah dari `DiscountCode`; dua model memakainya, rumusnya hidup sekali
- [x] Migrasi `create_event_discount_codes_table` (`unique(event_id, code)`, `once_per_user`)
- [x] Migrasi `add_discount_to_participant_orders` (5 kolom × `teams` + `race_entries`)
- [x] `App\Models\EventDiscountCode` + relasi `Event::discountCodes()`
- [x] `Team` / `RaceEntry`: fillable + casts + relasi `discountCode()`
- [x] `App\Services\EventDiscountCodeService` — `resolve()`, `usage()` (dua query dilipat)
- [x] `RegistrationService::startPayment(..., $discountCode)` + `discountedAmount()`
      (retry memakai snapshot), snapshot juga ditulis di cabang `$amount <= 0`
- [x] `RaceEntryService::hold(..., ?array $discount)` + resolve di `PublicRaceController`
- [x] `discount_code` di `RegisterTeamRequest`, `RegisterRaceEntryRequest`, `MyTeamController::pay`
- [x] `POST public/events/{orgSlug}/{eventSlug}/discount-preview` (dua rail, satu resolver)
- [x] CRUD organizer: `Api\EventDiscountCodeController`, `EventDiscountCodeRequest`,
      `EventDiscountCodeResource`, 4 rute di grup `tenant`
- [x] `TeamResource` / `RaceEntryResource` menerbitkan 4 field diskon
- [x] `EventResource.organization_slug` (dibutuhkan pratinjau dari halaman peserta)
- [x] PDF: `itemAmount` = harga katalog direkonstruksi; `_document.blade.php` tidak disentuh
- [x] `ParticipantDocumentService::html()` publik, supaya dokumen bisa diuji pada HTML
- [x] Mail: baris potongan di `registration-paid` & `race-entry-paid`

## Frontend

- [x] Tipe: `EventDiscountCode`, 4 field di `Team` & `RaceEntry`, `organization_slug`
      di `SportEvent`; `DiscountType`/`DiscountPreview` dipakai ulang
- [x] `lib/api/event-discount-codes.ts` (kuartet CRUD organizer)
- [x] `lib/api/discount.ts` → `previewParticipantDiscount()` (satu fungsi, dua rail)
- [x] `discount_code` di `RegisterTeamPayload`, `RegisterRaceEntryPayload`, `payRegistration()`
- [x] `components/payment/discount-code-field.tsx` — input + ringkasan harga, satu komponen
- [x] Form tim publik: dialog kini terbuka di **kedua rail**, `ChannelPicker` pakai `payable`
- [x] Form fun run: idem
- [x] `/participant/teams/{id}`: input hanya saat snapshot belum ada, badge kalau sudah
- [x] `/organizer/events/{id}/discount-codes` (list + form create/edit + neraca pemakaian)
- [x] Tombol "Kode Diskon" di header halaman edit event (tidak digerbang `isRace`)

## Verifikasi

- [x] `ParticipantDiscountCodeTest` — 19 test, semua **membandingkan** dua kasus
- [x] Suite penuh backend hijau (1075 test, 6461 assertion)
- [x] `tsc --noEmit` bersih, `bun run build` sukses (rute baru terbit)
- [x] `bun run lint` — 6 masalah tersisa semuanya pra-ada (`set-state-in-effect`
      di berkas yang tidak disentuh fitur ini)
- [ ] Uji manual di browser (buat kode → daftar → fee ikut turun → PDF → kuota habis)
- [ ] Deploy: `php artisan migrate` (dua migrasi baru, tanpa seeder — nol baris itu benar)

## Catatan yang dinyatakan terbuka

- `teams` tidak punya sapuan `expire-manual` seperti `race_entries`, jadi pendaftaran tim
  terlantar menahan satu slot kuota sampai organizer menolak atau manajer menarik diri.
  Itu katup yang organizer sudah punya; sapuan ketiga di sini akan jadi penulis kedua
  atas kuota pendaftaran. Ditulis di docblock `EventDiscountCodeService::usage()`.
- `RegistrationController@store` (entri offline organizer) **tidak** menerima kode —
  organizer mengetik sendiri angka yang ia mau.
