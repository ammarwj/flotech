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
| 3 | Template + editor drag-drop | 🚧 berjalan |
| 4 | Renderer + batch + ZIP | ⬜ |
| 5 | `id-cards:prune` | ⬜ |

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

- [ ] `api/config/id_card.php`, migrasi + model `IdCardTemplate` (mm di-cast **float**, bukan
      `decimal:2` yang mengembalikan string)
- [ ] `Store`/`UpdateIdCardTemplateRequest` dengan `after()`: foto wajib `w`/`h` & menolak
      `size`, teks kebalikannya
- [ ] `IdCardTemplateController` + 5 rute, `canvas-drag.ts`, tiga komponen `id-card/`, tiga
      halaman, entri sidebar (docblock "seven entries" → delapan), tipe
- [ ] Test `IdCardTemplateTest`

## Fase 4 — Renderer + batch (tidak boleh dipecah)

- [ ] Dua TTF di `api/resources/fonts/` **berikut lisensinya** dari upstream — salinan di
      `vendor/dompdf/` tidak membawa berkas lisensi dan `vendor/` di-gitignore
- [ ] `IdCardService`, `GenerateIdCardsJob`, request, `IdCardController` (4 metode), 4 rute,
      halaman generate + polling
- [ ] `config/horizon.php` memory 128 → 384
- [ ] Test `IdCardRenderTest`, `IdCardGenerateTest`

## Fase 5 — Kebersihan

- [ ] `id-cards:prune` + jadwal di `routes/console.php`

---

## Catatan yang belum diverifikasi

1. **Waktu & memori render** — 0,3–0,6 dtk/kartu itu estimasi. Benchmark 50 kartu sungguhan
   sebelum mengunci DPI 300 dan cap 500. Yang **sudah** terverifikasi: nginx tanpa
   `proxy_read_timeout` (default 60 dtk), CMD produksi `php artisan serve` satu-proses,
   `memory_limit=256M` — itulah kenapa rute sinkron tidak boleh ada sama sekali
2. `horizon.memory = 384` tebakan dari ukuran frame
3. `$timeout` job mengalahkan `timeout` supervisor — terdokumentasi, belum ditelusuri di
   vendor tree ini
4. Lisensi font (lihat Fase 4)
5. Rutin sudut membulat GD dibaca, belum dijalankan
6. `Catalog::officialRoleLabel()` belum ada — tambahkan atau petakan inline
7. `cover()` pada pasfoto 3:4 ke kotak 4:5 memotong ubun-ubun; `contain` katup keluarnya
8. Round-trip `decimal(6,2)` ↔ float JS untuk `85.6`
