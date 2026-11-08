# Progress: e-BIB untuk kategori fun run

Nomor dada digital untuk pelari. Bentuknya mengikuti generator ID card
(`docs/id-card-progress.md`, `docs/id-card-qr-email-progress.md`): template
beraset milik organizer + field berkoordinat persen, dirender GD jadi PNG,
batch zip di-queue, bisa dikirim email.

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan**
tambahkan trailer `Co-Authored-By:` atau `Claude-Session:`.

---

## Keputusan yang mengikat (sudah diambil, jangan dibuka ulang)

> **Tidak ada tabel `bibs`, dan ketiadaannya adalah intinya.** `id_cards` lahir
> karena pemain & ofisial **tidak punya baris bernomor** — kartu dulunya fungsi
> murni dari (orang, template), jadi QR tak punya apa pun untuk ditunjuk. Pelari
> tidak begitu: `race_entries` sudah baris persisten yang membawa nomornya
> (`bib_number`), kredensialnya (`qr_code`), dan alamatnya sendiri
> (`race_entries.email`, kolom — bukan `manager_user_id` yang dipinjam tim).
> Tabel kedua berarti dua baris per pelari yang bisa berselisih, **dan** nomor
> dokumen kedua di samping `bib_number` yang sudah ada — dua ejaan satu fakta,
> bentuk yang codebase ini terus tolak. Yang ditambahkan ke `race_entries` cuma
> dua kolom berkas: `bib_png_key`, `bib_sent_at`.

> **QR meng-encode `qr_code` check-in, bukan URL verifikasi.** Pembacanya sudah
> ada — `RaceScanController::checkIn()` memindai kolom itu di meja race pack —
> jadi e-BIB **adalah** tiket masuknya dan pelari membawa satu kertas. Halaman
> `/verify-bib/{nomor}` sengaja **tidak** dibuat: ia memberi pelari dua QR yang
> tampak sama dan memaksa marshal tahu mana yang mana, pembaca kedua atas satu
> dokumen. Konsekuensi yang mengikat: **e-BIB hanya terbit untuk entry lunas**,
> karena `qr_code` baru dicetak `markPaid()` — kredensial yang terbit sebelum
> uangnya masuk memasukkan orang yang belum bayar (alasan yang sudah tertulis di
> `RaceEntry::$fillable`).

> **Generator e-BIB adalah penulis pertama `bib_number`.** Kolomnya ada sejak
> fase fun run tanpa penulis, sengaja ditunda (`docs/fun-run-progress.md`).
> Nomor diberikan **per kategori**, urut, idempoten: entry yang sudah punya
> nomor tidak pernah dinomori ulang — QR dan nomor yang sudah dibagikan tidak
> boleh berubah. Organizer tetap bisa override lewat `RaceEntryEditDialog` yang
> sudah ada (`UpdateRaceEntryRequest` sudah menerima `bib_number`), dan
> **unique sengaja tidak ditegakkan** — alasan yang sudah tertulis di
> `race-entries` CRUD: urutannya milik organizer, dan unique index menolak
> penomoran ulang di tengah jalan.

- **Tabel `bib_templates` sendiri, bukan `kind` di `id_card_templates`.**
  Renderer GD-nya memang sama, tapi katalog field-nya beda total (nomor BIB
  besar, nama, kategori, nama event, QR — tanpa foto, tanpa `role_label`,
  tanpa `team_name`) dan default ukurannya A5 landscape, bukan CR80. Satu tabel
  dengan dua katalog berarti `IdCardTemplateRules` dan editornya bercabang atas
  `kind` di setiap aturan.
- **Feature key: `bib_generator` BARU.** Beda dari keputusan email ID card yang
  menolak key kedua: di sana key baru tak memisahkan paket mana pun. Di sini
  e-BIB adalah fitur jual untuk fun run, dan ia **wajib menyentuh `PlanSeeder` +
  `FeatureDefinitionSeeder`** (lihat invarian katalog di CLAUDE.md) — key yang
  punya nilai tanpa definisi tidak akan pernah muncul di halaman paket.
- **Digerbang dua kali, dan keduanya beda pertanyaan.** `bib_generator`
  (entitlement paket) **dan** `Catalog::scoringOf($event->sport_type) === 'none'`
  (event ini memang lomba) — pola `RaceCategoryController::ensureRaceEvent()`.
  Event sepak bola tidak punya pelari, jadi menawarkan e-BIB di sana adalah
  pengumuman tentang pintu yang tidak ada.
- **Satu email per pelari, dan itu menyederhanakan.** `groupForSending()` di
  `IdCardService` ada karena tiga pool menjawab "email siapa" dua cara berbeda;
  di sini satu kolom menjawabnya, jadi tidak ada pengelompokan — satu entry,
  satu mail, satu lampiran.
- **Pelari mengunduh sendiri** di `/race-entries/{id}`, rute publik di balik id
  entry bersama `invoice`/`receipt`, dengan alasan yang sudah tertulis di sana:
  pelari bisa tiba dari email dan belum tentu pernah login.

---

## Urutan fase (mengikat)

**1 sebelum 2** — renderer tak punya template untuk dibaca.
**2 sebelum 3** — batch merender lewat renderer.
**2 sebelum 5** — `bib_number` harus punya penulis sebelum ada yang mencetaknya.

| Fase | Isi | Status |
|---|---|---|
| 1 | Tabel `bib_templates` + CRUD + `config/bib.php` + feature key | ✅ selesai |
| 2 | `BibService`: penomoran, renderer PNG, QR check-in | ✅ selesai |
| 3 | Batch zip + job + endpoint organizer | ✅ selesai |
| 4 | Kirim email + unduh pelari | ✅ selesai |
| 5 | Frontend: editor template, halaman cetak, tombol unduh pelari | ✅ selesai |
| 6 | Sapuan media & prune | ✅ selesai |

---

## Fase 1 — Template & entitlement

- [x] Migrasi `create_bib_templates_table` — `organization_id` cascade, `name`,
      `background_url`, `width_mm`/`height_mm` default **A5 landscape**
      (210×148), `fields` json. Docblock menyatakan kenapa tak ada tabel `bibs`
      (lihat invarian di atas) — migrasi `id_card_templates` menyatakan
      ketiadaan `id_cards` sebagai keputusan sadar, dan di sini ketiadaannya
      **permanen**, bukan ditunda
- [x] `BibTemplate` model — cast `width_mm`/`height_mm` **float, bukan
      `decimal:2`** (cast itu mengembalikan string, dan renderer membaginya
      dengan 25.4; catatan yang sudah ada di `IdCardTemplate`)
- [x] `Organization::bibTemplates()` hasMany
- [x] `config/bib.php` — `dpi`, `max_recipients`, `fonts` (pakai ulang path
      `id_card.fonts`? **tidak** — eja sendiri, satu config satu rulebook),
      `fields`: `bib_number`, `name`, `category_name`, `event_name`, `qr`.
      **Tanpa `photo`** dan tanpa `number_prefix`/`verify_url`: nomornya
      `bib_number` dan tak ada halaman verifikasi
- [x] `BibTemplateRules` + Store/Update request — klon `IdCardTemplateRules`,
      `qr` di cabang kotak, tanpa cabang `photo`/`fit`/`radius`
- [x] `BibTemplateController` (`index`/`fields`/`store`/`update`/`destroy`) —
      gate `orgAllows('bib_generator')` (template org-scoped, monoton),
      `destroy` **ungated** seperti kembarannya
- [x] `PlanSeeder`: `bib_generator` → Professional saja. `FeatureDefinitionSeeder`:
      definisi + `feature_group` yang sama dengan `id_card_generator`
- [x] Migrasi katalog bergaya `align_plan_catalogue_with_production` untuk DB
      yang sudah diseed
- [x] Verifikasi: `pint --dirty`, tiga aktor satu payload

## Fase 2 — Penomoran & renderer

> **Invarian: nomor diberikan sekali, lalu tidak pernah berubah.** `assignNumbers()`
> hanya menyentuh entry yang `bib_number`-nya **null**, dan UPDATE-nya membawa
> `whereNull('bib_number')` **di dalamnya** — bukan sekadar membaca lalu menulis,
> pola yang sama dengan klaim kredit di `EventController::store()`. Dua batch
> bersamaan akan saling menimpa dan dua pelari membawa nomor yang sama di dada.
> Ujilah dengan **membandingkan** nomor sebelum/sesudah batch kedua: assert
> "semua entry punya nomor" lolos penuh walau batch kedua menomori ulang semuanya.

- [x] `BibService::assignNumbers(Event, RaceCategory)` — urut per kategori,
      format dari slug kategori + urut (`5K-001`), melewati yang sudah ada,
      `DB::transaction` + `lockForUpdate` atas nomor tertinggi kategori itu
      (pola `nextNumber()`, tanpa reset bulanan — lomba bukan dokumen bulanan)
- [x] `BibService::recipients(Event)` — entry **lunas** saja, dengan
      `category`, urut kategori lalu nama. Bentuknya flat seperti
      `IdCardService::recipients()`: `id`, `name`, `category_name`,
      `bib_number`, `qr_code`, `email`
- [x] `BibService::render(BibTemplate, array $person, ?ImageInterface $bg)` —
      pinjam bentuk `IdCardService::render()`: `drawText()` + `drawQr()`,
      **tanpa** `drawPhoto`/`placeholder`/`roundCorners`
- [x] **`drawQr()` meng-encode `qr_code` mentah, bukan URL.** Entry tanpa
      `qr_code` (belum lunas) tidak menggambar apa pun — cabang yang sama dengan
      `$issued === null` di ID card
- [x] Verifikasi: render dengan `qr` dan tanpa `qr` dalam satu test; bandingkan
      nomor sesudah dua batch

## Fase 3 — Batch zip

- [x] `BibService::BATCH_PREFIX = 'bib-batches'` dan
      `PRINT_PREFIX = 'bib-prints'` — prefix sendiri, **bukan** menumpang milik
      ID card: `id-cards:prune` menyapu prefix batch-nya wholesale, dan
      `bib-prints/` permanen karena barisnya menunjuknya
- [x] `BibService::zip()` + `storePrint()` + `put()`/`storage()`/`diskName()` —
      bentuk yang sama, termasuk flush zip tiap 25 dan tulis ke bucket (worker
      tak punya volume bersama dengan api)
- [x] `GenerateBibsJob` — `timeout = 900`, `tries = 1`, `TTL_HOURS = 24`,
      cache-keyed progress, `failed()` menulis 'failed'
- [x] `BibController@recipients|generate|status|download` — gate `bib_generator`
      **event-keyed** + `ensureRaceEvent()`, ownership batch lewat
      `organization_id` di cache (404 bukan 403 untuk batch asing)
- [x] `generate()` me-resolve penerima dari pool event-nya sendiri, bukan
      mempercayai id dari request
- [x] Verifikasi: suite, batch asing → 404

## Fase 4 — Email & unduh pelari

- [x] Migrasi `add_bib_columns_to_race_entries_table` — `bib_png_key` text
      nullable, `bib_sent_at` timestamp nullable
- [x] `SendBibsJob` — satu job per entry (tanpa pengelompokan: satu kolom email),
      `sendNow()` bukan `send()` supaya `bib_sent_at` tidak distempel pada mail
      yang masih di queue
- [x] `BibIssuedMail` — lampiran PNG dari `bib_png_key`
      (`Attachment::fromStorageDisk`, disk dari `BibService::diskName()` static),
      markdown `mail/bib-issued.blade.php`
- [x] `BibController@send` — entry tanpa `bib_png_key` dilewati dan
      **dilaporkan**, tidak menggagalkan batch
- [x] `PublicRaceController@bib` — `GET race-entries/{raceEntry}/bib`, di samping
      `invoice`/`receipt`. 404 saat `bib_png_key` null
- [x] `RaceEntryResource`: `bib_available` (bool, dari `bib_png_key !== null`) —
      **bukan** key-nya sendiri; klien tak pernah menyusunnya ulang
- [x] Verifikasi: `Mail::fake()`, bandingkan kegagalan vs keberhasilan transport
      pada satu entry

## Fase 5 — Frontend

- [x] `web/app/(dashboard)/organizer/bibs/` — klon struktur `id-cards/`:
      `page.tsx`, `generate/page.tsx`, `templates/new`, `templates/[id]`
- [x] `bib-template-editor.tsx` — klon `template-editor.tsx` tanpa cabang foto
- [x] Tombol "Unduh e-BIB" di `/race-entries/[entryId]` saat `bib_available`
- [x] `web/lib/api/bibs.ts` + `web/types/api.ts`
- [x] Nav organizer: entri e-BIB digerbang `scoring === 'none'`
- [x] Verifikasi: `bunx tsc --noEmit`, `bun run build`

## Fase 6 — Sapuan & prune

- [x] `MediaCleanupService::eventUrls()` — `race_entries.bib_png_key` ikut
      disapu saat event dihapus
- [x] `bibs:prune` — **hanya** `BATCH_PREFIX`, dan ujilah dengan
      **membandingkan** objek di kedua prefix sesudah sapuan
- [x] Verifikasi: suite penuh

**Verifikasi akhir**: `pint --dirty` bersih, suite penuh **1175 lulus / 7063 assertion**
(1 skip pre-existing), `bunx tsc --noEmit` bersih, `bun run build` sukses.

---

## Catatan yang belum diverifikasi

1. **Format nomor BIB.** `5K-001` diturunkan dari slug kategori. Organizer
   sungguhan mungkin mau prefix sendiri per gelombang; belum ditanyakan, dan
   override manual sudah tersedia lewat dialog edit
2. **Ukuran QR terpindai di A5.** Catatan ID card soal 33 modul di kotak 18mm
   berlaku terbalik di sini — A5 jauh lebih lega, jadi masalahnya kemungkinan
   tidak muncul. Belum diuji cetak
3. **Entry yang refund/batal sesudah e-BIB terbit.** PNG-nya tinggal dan
   `qr_code`-nya tetap ada, tapi `race-scan` menolak status non-`paid` — jadi
   kartunya mati di meja, bukan di berkasnya. Dinyatakan terbuka, bukan kelupaan
