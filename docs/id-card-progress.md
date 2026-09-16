# Progress: Generator ID Card

Organizer bisa mencetak kartu identitas untuk pemain tim, staf pertandingan, dan wasit.
Template diunggah sendiri, field (foto / nama / sebagai) ditaruh dengan drag-drop, lalu
semua kartu diunduh sekaligus sebagai satu `.zip` berisi 1 PNG per orang.

- **Rencana lengkap**: `/Users/ammar/.claude/plans/buatkan-fitur-generate-id-ethereal-gadget.md`

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Urutan fase (mengikat)

**2 sebelum 3** — kalau tidak, setiap penyimpanan template 403 di database hidup.
**3 sebelum 4** — job memuat template. Fase 1 dan 5 bebas.

| Fase | Isi | Status |
|---|---|---|
| 1 | Personel (wasit & staf) | ✅ selesai |
| 2 | Key gerbang `id_card_generator` | ✅ selesai |
| 3 | Template + editor drag-drop | ✅ selesai |
| 4 | Renderer + batch + ZIP | ✅ selesai |
| 5 | `id-cards:prune` | ✅ selesai |

---

## Fase 1 — Personel

- [x] Migrasi `2026_09_16_100000_create_event_personnel_table` — `event_id` (bukan `team_id`:
      wasit tidak berpihak), `kind` (`wasit|staf`) untuk filter "pilih semua wasit",
      `role_label` **teks bebas** karena judul jabatan panitia itu per-event, bukan properti
      cabang seperti `sport_official_roles`. `matches.referee_id` **tidak** ditambahkan
- [x] `EventPersonnel` — `protected $table = 'event_personnel'` wajib (Laravel memplural-kan
      jadi `event_personnels`). `roleLabel()` memberi fallback "Wasit"/"Staf" **saat dibaca**,
      bukan saat ditulis: kolom kosong berarti "tanpa jabatan", dan mengisinya di write path
      membuatnya tak bisa dibedakan dari orang yang jabatannya memang "Wasit"
- [x] `Event::personnel()` hasMany
- [x] `EventPersonnelService::sync()` — kontrak sama dengan `TeamRosterService::syncOfficials()`
      (ada `id` = update, tanpa = create, tidak dikirim = dihapus), termasuk `purgePruned()` →
      `PurgeMediaJob::afterCommit()`. Service sendiri, bukan metode di `TeamRosterService`:
      keduanya tidak berbagi satu aturan pun — bench memvalidasi peran ke katalog cabang,
      daftar ini tidak punya katalog sama sekali
- [x] `SyncEventPersonnelRequest` — `personnel.*.id` load-bearing, alasan sama dengan
      `officials.*.id`
- [x] `EventPersonnelResource` — mengirim `role_label` mentah **dan** `role_display` (fallback
      terpakai). Form bind ke yang mentah; yang terisi otomatis akan menulis "Wasit" ke kolom
      yang sengaja dikosongkan organizer
- [x] `EventPersonnelController` (`index`, `sync`) — **ungated**: ini data event biasa, gerbang
      menempel di yang memakainya. Rute `GET`/`PUT events/{event}/personnel` di grup `tenant`
- [x] `MediaCleanupService::eventUrls()` — `event_personnel.photo_url` ikut disapu saat event
      dihapus (barisnya cascade dari `event_id`, jadi `teamUrls()` tidak akan pernah melihatnya)
- [x] `web/lib/api/personnel.ts` + tipe `EventPersonnel` di `web/types/api.ts`
- [x] `web/components/event/personnel-editor.tsx` (meniru `team/official-editor.tsx`), dengan
      satu beda yang bukan kosmetik: jabatan itu `Input` teks bebas, bukan `Select` katalog.
      `placeholder`-nya = fallback yang akan dicetak kartu, jadi field kosong terbaca sebagai
      jabatan yang diwarisi, bukan blank
- [x] `web/app/(dashboard)/organizer/events/[id]/personnel/page.tsx` — daftar tersimpan
      **diturunkan** (`draft ?? saved`), tidak disalin ke state lewat `useEffect`. Effect yang
      menyemai tiap kali identitas `data` berubah juga akan menghapus ketikan organizer begitu
      ada refetch latar; lint `react-hooks/set-state-in-effect` menangkap polanya
- [x] Tombol "Petugas" di `web/app/(dashboard)/organizer/events/page.tsx`, komentar jumlah
      tombol diperbarui (enam → delapan) berikut alasan barisnya tetap aman di 390px
- [x] Test: `EventPersonnelTest` (6 kasus) + edit `MediaCleanupTest` — **12 lulus, 57 assertion**
- [x] Verifikasi web: `bunx tsc --noEmit` bersih, `bun run build` sukses, `bun run lint` tetap di
      baseline 2 error (keduanya berkas lama)

## Fase 2 — Key gerbang

- [x] `PlanSeeder` — `id_card_generator => 'true'` di **pro** & **professional** (bukan starter).
      Professional ikut **wajib**, bukan pilihan: `planCovers()` menuntut target upgrade memberi
      ≥ setiap fitur paket sekarang, jadi Professional tanpa key ini akan menolak Pro →
      Professional — upgrade paling jelas di katalog. Persis jebakan `platform_fee_percent`
- [x] `FeatureDefinitionSeeder` — `feature_group 'certificate'`, `boolean`, `sort_order` 140
- [x] Migrasi data `2026_09_16_100002_add_id_card_generator_feature` — seeder **tidak cukup**,
      `deploy.sh` cuma menyemai kalau `SEED=1`. Urutan: definisi dulu, baru nilai. Aditif:
      baris `plan_features` yang sudah ada dilewati, supaya `'false'` yang sengaja diketik
      super_admin di `/admin/plans` tidak diam-diam dinyalakan ulang
- [x] `CreatesPlannedEvents::fullPlan()`
- [x] `web/lib/plan.ts` `isIdCardEnabled` + docblock `anyEventAllows` (dua → tiga surface)
- [x] `PlanGate::orgAllows()` docblock — tiga kalimat yang jadi salah; heuristik "pemanggil
      ketiga = tandanya per-event" diganti aturan sebenarnya (yang boleh di sini hanya baris
      template org-scoped; apa pun yang ber-`event_id`, termasuk mencetak dari template itu,
      tetap event-keyed)
- [x] Verifikasi: `migrate` jalan di DB dev (pro & professional = `true`, label terbaca),
      **suite penuh 573 lulus / 3540 assertion** — termasuk test upgrade yang memakai katalog
      sungguhan, yang akan merah kalau Professional dilewatkan

## Fase 3 — Template

- [x] `api/config/id_card.php`, migrasi + model `IdCardTemplate` (mm di-cast **float**, bukan
      `decimal:2` yang mengembalikan string). Docblock config mencatat kenapa `qr` ditolak
      (tidak ada baris kartu, jadi tidak ada tujuan yang bisa dipindai) dan kenapa anchor foto
      beda dari anchor teks
- [x] `Store`/`UpdateIdCardTemplateRequest` dengan `after()`: foto wajib `w`/`h` & menolak
      `size`, teks kebalikannya. Aturan bersamanya di `IdCardTemplateRules` supaya pasangan
      store/update tidak bisa menyimpang
- [x] `IdCardTemplateController` + 5 rute. **Tanpa `withCount()`** kembaran sertifikat: tidak
      ada tabel kartu terbit. `destroy()` sengaja **ungated** — org yang paketnya habis tetap
      harus bisa membersihkan template yang tidak lagi bisa dipakainya
- [x] `canvas-drag.ts` — hanya separuh yang bebas geometri (listener di `window`, bukan elemen,
      atau drag mati begitu pointer melewatinya; `pointercancel` ditangani). Sistem koordinat
      tiap editor **tetap terpisah**: aturan satu-sumber berlaku untuk hal yang harus selalu
      sepakat, dan kedua editor ini justru sengaja berbeda
- [x] Tiga komponen `id-card/` (`card-size-picker`, `template-editor`, `template-form`). Form
      memakai `ImageUploadField`, bukan `<input type="file">` mentah seperti form sertifikat —
      itu melewatkan guard 5 MB dan `compressToWebp`
- [x] Tiga halaman `organizer/id-cards/` + entri sidebar (docblock "seven entries" → **delapan**),
      tipe di `web/types/api.ts`
- [x] Test `IdCardTemplateTest` (3 kasus) — **15 lulus, 78 assertion** pada filter
      `IdCard|EventPersonnel|MediaCleanup`. `test_card_size_round_trips_in_millimetres`
      meng-assert `85.6` sebagai float tapi `54`/`105`/`148` sebagai **int**: float bulat
      di-encode JSON jadi `54` dan kembali sebagai int. Cast `decimal:2` tetap tertangkap —
      ia mengirim string `"54.00"`
- [x] Verifikasi web: `bunx tsc --noEmit` bersih, `bun run build` sukses, `bun run lint` tetap di
      baseline 2 error (keduanya berkas lama)

## Fase 4 — Renderer + batch (tidak boleh dipecah)

- [x] `DejaVuSans.ttf` + `DejaVuSans-Bold.ttf` di `api/resources/fonts/` **berikut
      `LICENSE-DejaVu.txt`** dari upstream — salinan di `vendor/dompdf/` tidak membawa berkas
      lisensi dan `vendor/` di-gitignore
- [x] `Catalog::officialRoleLabel(?string $sport, ?string $key)` — label peran ofisial tim
      dari katalog cabang, `null` untuk key yang tidak dikenal **dan** untuk ofisial tanpa
      peran; "Ofisial" dipasang di pemanggil, bukan di helper
- [x] `IdCardService` — `recipients()` meratakan tiga kolam jadi satu bentuk, `background()`
      di-decode **sekali per batch** lalu di-`clone` per kartu, `render()` → PNG,
      `zip()` → key objek. Tiga fakta Intervention v4 yang **dikoreksi dengan menjalankan**,
      bukan dengan membaca: pabriknya `createImage()` (`create()` tidak ada),
      `colorAt()` (`pickColor()` tidak ada), dan vertical align `'center'` — enum `Alignment`
      tidak punya `middle`, dan `Font::setAlignmentVertical()` memakai `Alignment::from()`
      telanjang sehingga alias fuzzy di `Alignment::create()` tidak berlaku untuk font
- [x] **Fallback disk, dan ini bukan kenyamanan dev.** Disk `r2` dikonfigurasi
      `throw => false, report => false`, jadi tanpa kredensial `put()` **no-op tanpa suara**
      dan unduhan 404 atas objek yang tak pernah ada. `zip()` bercabang di `config('r2.key')`
      (pola `UploadController`), dan satu-satunya yang memutuskan disknya adalah
      `IdCardService::storage()` — `download()` membaca lewat sana, bukan `Storage::disk('r2')`
      langsung, karena dua pembaca aturan yang sama akan menyimpang dan selisihnya berupa
      404 atas berkas yang ada. `fetchBytes()` menelusuri **dua** base (R2 publik dan
      `public`), pasangan yang sama dengan `MediaCleanupService::keyFor()`
- [x] `GenerateIdCardsJob` (`$timeout = 900`, `$tries = 1`), `GenerateIdCardsRequest`,
      `IdCardController` (4 metode), 4 rute. `status()`/`download()` **ungated** tapi wajib
      memeriksa kepemilikan — batch id UUID di cache bersama, tanpa itu ia jadi bearer token;
      404, bukan 403, karena keberadaan sebuah id pun bukan hal yang boleh dipelajari orang asing
- [x] `config/horizon.php` memory 128 → 384
- [x] `deploy.md` — `CACHE_STORE=redis` & `QUEUE_CONNECTION=redis` masuk daftar env wajib,
      berikut gotcha-nya: `file`/`array` lokal per container, jadi `worker` menulis status batch
      ke cache-nya sendiri dan `api` membaca cache-nya sendiri **tanpa satu pun error** —
      halaman generate mentok "queued" selamanya walau zip-nya benar-benar jadi. Satu baris
      troubleshooting juga
- [x] `web/lib/api/id-cards.ts` (`getIdCardRecipients`, `generateIdCards`, `getIdCardBatch`,
      `downloadIdCardBatch`) + tipe `IdCardRecipient`/`IdCardBatch`. Unduhan lewat
      `apiClient` `responseType: "blob"` + `downloadBlob()` — token di memori, `<a href>` polos
      akan 401
- [x] `organizer/id-cards/generate/page.tsx` — polling `["id-card-batch", orgId, batchId]` yang
      **berhenti di `done` DAN `failed`**: batch gagal sama finalnya dengan batch selesai, dan
      memoll keduanya selamanya membuat tab yang dibiarkan terbuka menghantam API tanpa henti.
      Gate dibaca dari **event yang dipilih**, bukan sekali di atas halaman — entitlement milik
      event, jadi mengganti pilihan bisa mengubah jawabannya. Mengganti event/template
      mengosongkan batch di layar: ia dirender dari pasangan yang lama
- [x] Test `IdCardRenderTest` (3 kasus, 8 assertion) + `IdCardGenerateTest` (4 kasus,
      19 assertion). Ukuran PNG diverifikasi **dengan dijalankan**: CR80 → 1011×638,
      A6 → 1240×1748 pada DPI 300
- [x] Verifikasi: `pint --dirty` bersih, **suite penuh 583 lulus / 3585 assertion** (1 skip
      lama), `bunx tsc --noEmit` bersih, `bun run build` sukses, `bun run lint` tetap di
      baseline 2 error (keduanya berkas lama)

## Fase 5 — Kebersihan

- [x] **Prefix zip dipindah `id-cards/` → `id-card-batches/`** (`IdCardService::BATCH_PREFIX`).
      Ini ditemukan saat menulis perintahnya, bukan direncanakan: latar template diunggah ke
      `id-cards/` juga (`template-form.tsx` mengirim `folder="id-cards"`), jadi sapuan "hapus yang
      lebih tua dari 24 jam di bawah `id-cards/`" akan menghapus setiap desain kartu yang umurnya
      lewat sehari. Pengecekan ekstensi `.zip` saja terlalu tipis untuk dipasang di depan loop hapus
- [x] `PruneIdCardBatches` (`id-cards:prune {--hours=}`) — cutoff default membaca
      `GenerateIdCardsJob::TTL_HOURS`, bukan angka sendiri: entri cache yang menamai key itu
      kedaluwarsa di jam yang sama, jadi objek yang lebih tua sudah tidak bisa diunduh siapa pun.
      Disknya lewat `IdCardService::storage()`, bukan `Storage::disk('r2')` — alasan yang sama
      dengan `download()`: dua pembaca aturan yang sama akan menyapu bucket yang salah
- [x] Jadwal `dailyAt('02:20')` di `routes/console.php`, di sebelah `views:prune`
- [x] Test `PruneIdCardBatchesTest` (2 kasus) — yang kedua ada khusus untuk jebakan prefix:
      latar template umur 500 jam **selamat** sementara zip umur 500 jam hilang. Yang pertama
      membandingkan zip 30 jam (hilang) dengan zip 2 jam (bertahan)
- [x] Verifikasi: `pint --dirty` bersih, **suite penuh 585 lulus / 3591 assertion**

---

## Catatan yang belum diverifikasi

1. **Waktu & memori render** — 0,3–0,6 dtk/kartu itu estimasi. Benchmark 50 kartu sungguhan
   sebelum mengunci DPI 300 dan cap 500. Yang **sudah** terverifikasi: nginx tanpa
   `proxy_read_timeout` (default 60 dtk), CMD produksi `php artisan serve` satu-proses,
   `memory_limit=256M` — itulah kenapa rute sinkron tidak boleh ada sama sekali
2. `horizon.memory = 384` tebakan dari ukuran frame
3. `$timeout` job mengalahkan `timeout` supervisor — terdokumentasi, belum ditelusuri di
   vendor tree ini
4. ~~Lisensi font~~ — **selesai**: DejaVu diambil dari upstream berikut `LICENSE-DejaVu.txt`
5. **Rutin sudut membulat GD** masih dibaca, belum diuji: kedua test render memakai
   `radius => 0`, jadi jalurnya cuma dilewati kompilernya. Uji manual dengan `radius: 50`
   (lingkaran) sebelum mempercayainya di kartu sungguhan
6. ~~`Catalog::officialRoleLabel()` belum ada~~ — **selesai**, ditambahkan di Fase 4
7. `cover()` pada pasfoto 3:4 ke kotak 4:5 memotong ubun-ubun; `contain` katup keluarnya
8. Round-trip `decimal(6,2)` ↔ float JS untuk `85.6`
