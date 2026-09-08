# Formulir pendaftaran yang bisa diatur organizer — progress

Rencana lengkap: `~/.claude/plans/saat-register-tim-tambahkan-reactive-karp.md`

Organizer mendefinisikan per event: jenis dokumen (tim & pemain, wajib/opsional, format yang
diterima) dan field dinamis (tim & pemain: short_text / long_text / select / date). Skema kosong
= tidak ada UI unggah sama sekali.

## Invarian yang dijaga

- **Kelengkapan per baris pemain**: begitu nama pemain diketik, baris itu wajib lengkap. Roster
  kosong tetap sah (nol baris = nol pemeriksaan, konsekuensi bentuk loop, bukan `if` khusus).
- **Dokumen pemain bersarang** di `players.*.documents[]`, bukan list datar ber-`player_id` —
  pemain baru belum punya id saat request disusun.
- **Urutan di `syncPlayers()`**: upsert → sync dokumen → **baru** assert. Terbalik = peserta yang
  menyimpan tanpa mengubah apa pun ikut ditolak.
- **`syncDocuments()` dibatasi lingkupnya** per konteks (tim = `player_id IS NULL`, pemain =
  `player_id = X`). Tanpa itu satu sync pemain menyapu seluruh dokumen tim berikut filenya.
- **`team_id` tetap non-null** untuk dokumen pemain → `MediaCleanupService::teamUrls()` tak berubah.
- **Skema publik, jawaban tidak**: `PublicEventResource` memuat `registration_form` tapi tidak
  pernah `custom_fields`.

## Backend

- [x] Migrasi: `registration_form` di `events`
- [x] Migrasi: `custom_fields` di `teams` + `players`
- [x] Migrasi: `player_id` di `registration_documents`
- [x] `app/Support/RegistrationForm.php` (value object + validationRules + normalisasi)
- [x] `app/Http/Requests/Event/TeamPayloadRules.php` (rule bersama, hapus duplikasi)
- [x] `app/Http/Requests/Event/SyncRegistrationFormRequest.php`
- [x] Model: `Team`, `Player`, `RegistrationDocument` (fillable + cast + relasi)
- [x] Model `Event`: `registration_form` di `$fillable` **dan** `casts()` — dilupakan mula-mula;
      `$model->update()` menelan kolom di luar `$fillable` tanpa error, jadi setiap simpan skema
      "berhasil" tapi tidak menulis apa pun. Ditangkap test, bukan review. (Pelajaran yang sama
      sudah tertulis untuk `events:backfill-plan`.)
- [x] `TeamRosterService`: `rowErrors()`, `syncDocumentRows()` scoped + updatable, `syncPlayers()`,
      `applyCustomFields()`, `documentTypeError()`
- [x] `EventController`: `registrationForm()` + `syncRegistrationForm()` (guard key-in-use)
- [x] Controller pendaftaran (4 jalur) meneruskan `custom_fields`
      (`PublicEventController::register()` sekalian dibungkus `DB::transaction` — sebelumnya tidak,
      jadi roster yang ditolak meninggalkan baris tim)
- [x] `UploadController::document()` — WebP re-encode, maks 5MB, mimes allow-list
- [x] Resources: `TeamResource`, `EventResource`, `PublicEventResource`
- [x] `RegistrationsExport` — kolom dinamis
- [x] Routes (`GET`/`PUT events/{event}/registration-form`, `POST uploads/document`)

## Frontend

- [x] `lib/registration-form.ts` (cermin klien)
- [x] `components/team/document-upload-field.tsx`
- [x] `components/team/custom-field-editor.tsx`
- [x] `organizer/events/[id]/registration-form/page.tsx` (pembangun skema) + tombol masuk di
      halaman edit event
- [x] `roster-editor.tsx` — field + dokumen per pemain; prop `schema` ber-default `EMPTY_SCHEMA`
      sehingga event tanpa skema merender roster yang persis sama seperti sebelumnya
- [x] 3 form pendaftaran (publik, dialog organizer, participant). Dialog organizer **dapat UI
      dokumen untuk pertama kalinya**; blok "Dokumen (opsional)" yang selalu tampil di form publik
      & halaman participant dihapus, berikut dua salinan `handleFiles` yang memakai `/uploads/sign`
      tanpa validasi apa pun.
- [x] `registrations/page.tsx` — jawaban tim jadi baris `Info`, jawaban & dokumen pemain menempel
      di baris pemainnya; judul dokumen diambil dari **label slot**, bukan nama file
- [x] `lib/api/events.ts` + `types/api.ts`

Catatan frontend:
- **Dokumen lama tanpa `document_type` disaring saat seed**, di halaman participant dan dialog
  organizer. Begitu event mendefinisikan slot, server menolak dokumen tak bertipe — dan UI yang
  dirender dari skema tidak punya tempat menampilkannya, jadi ia akan duduk tak terlihat dan
  mem-422-kan **setiap** simpan. Event tanpa slot melewatkan semuanya apa adanya.
- **Gate Simpan = `teamMissing` + `hasIncompletePlayer()`** di ketiga form. Servernya menolak baris
  setengah jadi secara semua-atau-tidak, jadi tanpa gate ini satu baris kurang berkas membuang
  seluruh isi form.

## Test

- [x] `RegistrationFormTest.php` — 17 test, semua membandingkan dua keadaan
- [x] `RegistrationTest.php` & `MyTeamTest.php` tetap hijau (suite penuh: 520 test hijau)

- [x] E2E regresi (`team-registration.spec.ts` + `manual-team.spec.ts`, 10 hijau) — dijalankan ulang
      **sesudah** ketiga form dikonversi: event tanpa skema merender form yang persis sama seperti
      sebelumnya. **Belum menguji fitur ini sendiri** (skema terisi).

Catatan test:
- `test_public_resource_carries_the_schema_but_no_answers` merender `PublicEventResource`
  langsung, **bukan** lewat endpoint: `PublicEventController::show()` mengurutkan roster dengan
  `orderByRaw("jersey_number ~ '^[0-9]+$'")` yang khas Postgres, dan suite jalan di SQLite. Bug
  lama yang tidak ada hubungannya dengan fitur ini; penyaringannya sendiri memang hidup di resource.
- Assert dilakukan atas **body mentah** (`assertStringNotContainsString`), bukan path JSON, supaya
  nesting baru tidak bisa menyelundupkan jawaban lewat assert yang cuma mengecek satu path.
- Sepuluh e2e sempat gagal semua di fixture (`grantCredit`) karena `payment_gateway_enabled` mati di
  DB dev — sisa run `@gateway-off` yang crash, tidak ada hubungannya dengan fitur ini. `globalSetup`
  sekarang memeriksanya di depan; pesannya dulu berbunyi seperti checkout rusak.
