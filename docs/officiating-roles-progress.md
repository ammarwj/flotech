# Progress: Role `referee` & `staff` — akun tugas per event

Petugas pertandingan (`event_personnel`) sekarang bukan cuma nama di kartu identitas:
organizer mengisi **email** di samping tiap petugas, sistem mengirim akses login
(password default `welcomefloevent1`, wajib diganti saat login pertama), lalu
**staff** mengisi skor/kartu/pencetak gol/assist dan mencetak susunan pemain,
sementara **referee** meng-acc susunan pemain yang dikirim manajer tim secara online.

- **Rencana lengkap**: `/Users/ammar/.claude/plans/mellow-sniffing-owl.md`

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Urutan fase (mengikat)

**1 sebelum 3** — middleware fase 3 bercabang atas nilai `kind`; kalau nilainya masih
`wasit`/`staf` sementara konstantanya sudah Inggris, yang salah bukan error melainkan
orang yang salah diloloskan.
**2 sebelum 3** — email undangan fase 3 menjanjikan "password wajib diganti"; janji itu
harus sudah ditegakkan sebelum email pertama terkirim.
**3 sebelum 4–7** — semuanya masuk lewat pintu yang sama (`event.personnel`).
**5 sebelum 6 sebelum 7** — gerbang cetak membaca status `approved`.

| Fase | Isi | Status |
|---|---|---|
| 1 | Rename `kind` → `referee`/`staff` | ✅ selesai |
| 2 | `must_change_password` + rotasi paksa | ✅ selesai |
| 3 | Akun tugas: `email`/`user_id`, undangan, middleware, shell `/officiating` | ✅ selesai |
| 4 | Permukaan staff: skor + statistik | ✅ selesai |
| 5 | Tabel lineup + submit manajer | ✅ selesai |
| 6 | Acc wasit | ⬜ belum |
| 7 | Lembar susunan pemain (PDF) + gerbang cetak | ⬜ belum |

---

## Fase 1 — Rename `kind` ke Inggris

- [x] Migrasi data `2026_09_16_120000_rename_event_personnel_kinds` — `UPDATE` murni, kolomnya
      `string(20)` tanpa enum DB jadi tidak ada perubahan skema. `down()` membalik dengan
      tepat: rollback yang meninggalkan `referee` di kolom membuat `KIND_LABELS` jatuh ke
      "Petugas" di setiap kartu
- [x] `EventPersonnel::KINDS = ['referee', 'staff']`, `KIND_LABELS = ['referee' => 'Wasit',
      'staff' => 'Staf']` — **kuncinya berganti, labelnya tidak**. Itulah seluruh isi
      keputusan: satu kosakata tersimpan, satu kosakata tampil
- [x] `roleLabel()` tetap fallback **saat dibaca** — tidak disentuh fase ini
- [x] Komentar migrasi `2026_09_16_100000_create_event_personnel_table:34`
- [x] `tests/Feature/EventPersonnelTest.php` + `tests/Feature/MediaCleanupTest.php` — semua
      nilai `kind`. `MediaCleanupTest:129` `personnel/wasit.webp` **sengaja dibiarkan**: itu
      nama berkas fixture, bukan nilai `kind`
- [x] `web/types/api.ts` — `EventPersonnelKind = "referee" | "staff"`
- [x] `web/components/event/personnel-editor.tsx` — `emptyPersonnel` default, `KIND_OPTIONS`,
      `placeholder`, dua tombol tambah. Label tombol dan opsi tetap "Wasit"/"Staf"
- [x] Uji baru `test_the_old_indonesian_kinds_are_refused_and_the_english_ones_accepted` —
      **dibandingkan berpasangan**: `'wasit'` → 422 *dan* `'referee'` → 200 dengan
      `role_display` tetap "Wasit". Assert 422-nya saja akan lolos walau aturannya menolak
      semua nilai; assert 200-nya saja akan lolos walau nilai lama masih diterima diam-diam

**Copy Indonesia yang sengaja TIDAK diubah** (prosa yang dibaca manusia, bukan nilai):
`2026_09_16_100002_add_id_card_generator_feature.php:49`, `FeatureDefinitionSeeder.php:134`,
`organizer/id-cards/page.tsx:26`, `id-cards/generate/page.tsx:40,120`,
`events/[id]/personnel/page.tsx:112`.

**Bahaya urutan deploy.** Kode dan migrasi harus berangkat bersama. Deploy kode dulu →
`Rule::in(EventPersonnel::KINDS)` menolak setiap baris lama pada penyimpanan berikutnya;
migrasi dulu → payload `"wasit"` dari frontend lama yang ditolak.

---

## Fase 2 — `must_change_password`

- [x] Migrasi `2026_09_16_120001_add_must_change_password_to_users_table` — boolean, default
      `false`, jadi setiap akun lama dan setiap akun yang mendaftar sendiri tak tersentuh
- [x] `User` cast `'must_change_password' => 'boolean'`, **sengaja tidak `$fillable`** — ini
      kunci, dan kunci yang bisa dibuka mass assignment bukan kunci. Ditulis hanya lewat
      `forceFill()`, posisi yang sama dengan `password`/`remember_token`
- [x] `UserResource` menerbitkannya **selalu** (bukan `whenLoaded`) — `me()` satu-satunya
      sumbernya setelah reload. Sekali absen, gerbang membaca `undefined` yang falsy dan
      terlihat benar, sampai suatu hari key-nya juga hilang untuk yang seharusnya digerbang
- [x] `AuthController::updatePassword()` menambah `'must_change_password' => false` ke
      `forceFill()` yang sudah ada — **tidak ada endpoint baru**. Endpoint "set password
      pertama" harus membuang `current_password` dan ikut membuang
      `different:current_password`, yang justru satu-satunya aturan yang membuat rotasinya
      berarti: tanpa itu default di kotak masuk bisa dipasang ulang
- [x] `EnsurePasswordRotated` (alias `password.rotated`) — penegakan di **server**, bukan cuma
      UI. **Tidak pernah global**: pasang di seluruh API dan `auth/me` ikut 403, sehingga
      shell tak punya cara tahu kenapa ia ditolak dan tak punya apa pun untuk dirender —
      kunci dengan anak kuncinya terkunci di dalam. Allowlist-nya struktural (endpoint auth
      hidup di luar setiap grup yang memakai middleware ini), bukan daftar yang harus diingat
- [x] `web/lib/password.ts` — skema zod diekstrak dari `account/page.tsx`. Dua salinan aturan
      kekuatan menyimpang satu arah saja: yang tak diperhatikan jadi longgar, dan 422-nya
      mendarat di layar yang tak bisa ditinggalkan user
- [x] `web/components/auth/force-password-gate.tsx` di dalam `AuthGate` — **takeover**, bukan
      redirect rute. Redirect menyisakan setiap URL dashboard lain bisa diketik, dan yang
      mengetiknya dapat 403 tanpa layar penjelas. Ada tombol "Keluar": tanpa itu akun yang
      salah adalah jalan buntu, karena tidak ada header dan tidak ada menu
- [x] Gerbang dijaga pada `user` sudah termuat, bukan cuma flagnya — `ready` menyala saat token
      mendarat, satu tick sebelum `me()` selesai, jadi `user` sesaat null untuk semua orang
- [x] `login/page.tsx` — cabang `must_change_password` **paling atas**, sebelum cabang lain:
      akun undangan tidak punya organisasi, jadi cabang organizer akan melemparnya ke
      `/onboarding` yang berada di route group sendiri, tanpa `AuthGate` dan tanpa takeover
- [x] `tests/Feature/MustChangePasswordTest.php` — 6 kasus, semuanya berpasangan: user biasa vs
      ber-flag pada URL yang sama; sebelum vs sesudah ganti password; **hash tersimpan** saat
      default ditolak (422 yang terbit setelah tulis akan terlihat identik dari luar); rute
      tugas (403) vs `auth/me` (200)

---

## Fase 3 — Akun tugas per event

- [x] Migrasi `2026_09_16_120002_add_account_columns_to_event_personnel_table` — `email`
      nullable, `user_id` foreignUuid nullable `nullOnDelete`, index `['event_id', 'user_id']`
- [x] `EventPersonnel::$fillable` += `email`, `user_id`; relasi `user()`
- [x] Provisioning idempoten di `EventPersonnelService::sync()` — PUT ini jalan di **setiap**
      simpan. Cabang "email sama dan `user_id` ada" → **tidak melakukan apa pun**; tanpa itu
      setiap penyimpanan formulir mengirim ulang password ke semua petugas
- [x] Akun yang **sudah ada**: password tidak pernah disentuh, flag tidak pernah diset, mail
      tanpa password. Tanpa aturan ini, mengetik email orang lain di baris petugas menjadi
      primitif pengambilalihan akun
- [x] Menghapus baris petugas **tidak** menghapus akunnya — aksesnya mati karena middleware
      mencari baris petugas, bukan karena akunnya lenyap
- [x] `PersonnelInvited` notification + view markdown; mail lewat `->afterCommit()`
- [x] `EventPersonnelScope` (alias `event.personnel`) + `event.staff` / `event.referee`
- [x] Tier rute `officiating/events/{event}` **di luar** `organizations/{organization}`.
      **Jangan** menyetel attribute `organization` di middleware ini — begitu ada, satu rute
      nyasar memberi wasit seluruh API organizer
- [x] `default_mode = 'officiating'` di dua `Rule::in`; `UserResource` + `sidebar-nav`
- [x] `tests/Feature/OfficiatingAccessTest.php` — bandingkan rute `officiating/...` (200)
      dengan rute organizer setara (403); bandingkan jumlah mail di dua PUT identik (1, bukan
      2); bandingkan `users` count sebelum/sesudah sync yang membuang satu petugas

**Frontend fase 3** — `lib/api/officiating.ts` (semua path `/officiating/...`, **tidak pernah**
cabang di bawah `/organizations/{org}/...`), `officiating/page.tsx` (daftar penugasan),
`officiating/events/[id]/page.tsx` (jadwal). Tiga catatan yang perlu dibawa ke fase berikutnya:

- **Tidak ada redirect saat penugasannya cuma satu**, menyimpang dari rencana. Kru turnamen
  akhir pekan hampir selalu punya tepat satu, dan dengan redirect mereka tak pernah punya
  halaman yang menyebut mereka ditugaskan sebagai apa. Kartunya satu klik dan memuat perannya.
- **`CrewMatchCard` ditulis lokal, bukan memakai `PublicMatchCard`.** Kartu publik seluruhnya
  bergaya dari `app/(public)/event-shell.css` yang **tidak dimuat shell dashboard**
  (`globals.css:1188` sudah menyatakannya), ia sebuah `<button>` yang butuh `onClick`, dan
  prop `bans`/`sport`/`disciplineRules`-nya sengaja wajib — sementara query disiplin baru
  lahir di fase 4. `MatchCardHeader` tidak mungkin: ia butuh `orgId` dan merender kontrol
  tulis organizer.
- **`ChoosableMode`** (`use-dashboard-mode.ts`) memisahkan mode yang bisa **dipilih** dari
  `DashboardMode`. Tanpa itu `MODE_PITCH` di halaman daftar akun wajib mengarang copy untuk
  `officiating` — dan copy yang ada terbaca sebagai tawaran, padahal tidak ada yang mendaftar
  jadi petugas; organizer yang menaruhnya di sana.
- `login/page.tsx` cabang `must_change_password` sekarang menuju `/officiating`, bukan
  `/account`: gerbangnya tetap menyala di keduanya, tapi yang ini juga mendaratkan mereka di
  tempat yang benar begitu passwordnya diganti.
- `sidebar-nav.tsx` `OFFICIATING_NAV` **satu baris saja**, dan sengaja tidak masuk cabang
  exact-match `isActive()` — ia tak punya saudara untuk ditelan, jadi ia tetap menyala selagi
  kru berada di dalam event, yang adalah sepanjang hari kerjanya.

---

## Fase 4 — Staff: skor & statistik

- [x] Ekstrak badan `MatchController::saveMatchStats()` ke `MatchStatService::replace()` —
      dua pintu yang menulis hal yang sama akan menyimpang. Ikut pindah: `snapshot()`,
      `rules()`, `teamRoster()`. Ketiganya adalah **pembacaan** dari tally yang sama, dan
      membiarkannya di controller berarti pintu officiating menyusun ulang bentuk payload
      yang sudah dibentuk di tempat lain — dua pembaca satu tabel, bentuk bug yang sama
      dengan dua pembaca `stage`
- [x] `updateResult()` staff selalu `$autoConfirm = false` — aturan operator yang sudah ada,
      apa adanya: yang mencatat bukan yang meratifikasi. Toast-nya menyebutkannya
      (`'Hasil disimpan — menunggu konfirmasi admin'`), karena "tersimpan" tanpa embel-embel
      terbaca sebagai "masuk klasemen"
- [x] `MatchResultException` + `payloadFrom()` — `MatchResultService::apply()` sudah menolak
      kategori ber-partai (422 `['rubbers' => …]`), dan kedua pintu sekarang melempar lewat
      satu jalur alih-alih masing-masing menyusun response-nya sendiri
- [x] Kartu tetap dikenali dari `sport_stats.role`, tidak pernah dari `stat_key` — kolomnya
      datang dari `Catalog::statColumns()` lewat `MatchStatService`, jadi permukaan baru ini
      tidak punya daftar hardcode untuk disimpangkan
- [x] Penulis kelima kontrak invalidasi `["discipline", …]` lahir di sini — keynya
      `["officiating-discipline", eventId, catId]`, **tanpa org id**, karena akun tugas tidak
      punya organisasi. Bentuk key yang berbeda itulah yang membuat key organizer yang nyasar
      tidak mungkin cocok
- [x] Bandingkan simpan staff vs admin pada laga sama: `confirmed_at` null vs terisi —
      `OfficiatingMatchTest`, 4 kasus / 30 assertion; sapuan penuh 606 hijau

**Frontend fase 4** — editornya **tidak disalin**. `web/lib/match-doors.ts` lahir di sini:
empat factory gateway (`organizerStatsGateway`, `organizerResultGateway`,
`officiatingStatsGateway`, `officiatingResultGateway`) yang membawa fungsi endpoint **dan**
kontrak invalidasinya, dan `MatchStatsEditor`/`SetScoreEditor` berganti prop dari
`{orgId, eventId, match}` jadi `{gateway, match}`. Ini cerminan klien dari alasan
`MatchResultService`/`MatchStatService` ada sama sekali.

- **`GoalScoreEditor` diekstrak** dari baris skor inline di `MatchCard` organizer. Layak
  dilakukan demi adu penaltinya saja: *"knockout imbang wajib penalti"* ditegakkan server di
  kedua pintu, dan salinan kedua kondisi itu di klien adalah layar yang menawarkan simpan
  yang pasti ditolak API — atau lebih buruk, yang diam-diam membuang skor penaltinya dan
  mendudukkan tim yang salah di babak berikutnya.
- **`canScore` cuma presentasi.** Wasit mendapat kartu tanpa kontrol, tapi yang menegakkan
  tetap `event.staff` yang menjawab 403 apa pun yang dirender komponen ini.
- **Kategori ber-partai jatuh ke teks, bukan form.** `MatchResultService` menolaknya 422 dan
  tidak ada rute partai di tier officiating; menampilkan field-nya berarti menawarkan simpan
  yang tidak pernah bisa berhasil.
- **Laga `cancelled` dan slot ber-TBD juga read-only** — tidak ada yang bisa dicatat dari
  laga yang tidak dimainkan, dan itu keadaan yang sama yang dilihat wasit.

---

## Fase 5 — Lineup per-laga per-tim

- [x] `match_lineups` (`unique(['match_id','team_id'])`), `match_lineup_players`,
      `match_lineup_officials` — **tiga tabel**, bukan satu dengan kolom diskriminator
      nullable: kolom nullable itu persis cara invarian "ofisial bukan pemain" bocor kembali
- [x] `LineupService::sync()` kontrak full-list yang sama dengan `TeamRosterService`
- [x] Terkunci saat `submitted`/`approved` — itu yang membuat acc berarti sesuatu
- [x] Rute `my-teams/{team}/matches/...` lewat `scope()` yang sudah ada
- [x] `DisciplineService:33` diperbarui satu baris: lineup kini ada, **sengaja tidak dibaca**
      di sini (lineup adalah pengajuan sebelum kick-off, bukan catatan siapa yang turun)
- [x] `tests/Feature/MatchLineupTest.php` — 8 kasus / 87 assertion; sapuan penuh **618 hijau
      (3803 assertion)**, +8 dari baseline 610 yaitu tepat berkas baru ini

**Penyimpangan yang dicatat.**

- **`LineupService::sync()` mencari baris yang sudah ada lewat kunci naturalnya, bukan cuma
  `id`.** Ini bukan ikat pinggang-dan-bretel, ini perbaikan 500 sungguhan yang ditemukan
  testnya: `unique(lineup_id, player_id)` membuat **pemain itulah identitas barisnya**, dan
  editor yang menyusun ulang daftarnya dari roster — yang adalah bentuk di kawat dari
  memindahkan orang dari bangku ke sebelas awal — mengirim pemain yang sama tanpa `id` dan
  menabrak index itu. Cabang `id` tetap yang pertama dibaca dan tetap load-bearing; fallback
  cuma menutup jalur tanpa id. Punya testnya sendiri
  (`test_the_same_player_sent_back_without_a_row_id_moves_instead_of_colliding`) yang
  membandingkan simpan **dengan** id lalu **tanpa** id, dan mengunci
  `assertDatabaseCount('match_lineup_players', 1)` — assert "200" saja akan lolos walau
  barisnya berlipat.
- **`MyTeamMatchController` menerbitkan `sport_type` + `timezone` di `team`.** Keduanya bukan
  hiasan: label peran ofisial datang dari katalog cabang (jangan pernah di-hardcode), dan
  `lib/match-dates.ts` menyatakan tz sebagai parameter **wajib** justru supaya pemanggil yang
  lupa gagal dikompilasi alih-alih diam-diam kembali ke jam pembaca. Satu payload, bukan
  request kedua ke `my-teams/{team}`.
- **Scene test dibangun dari endpoint sungguhan ujung ke ujung** — daftar publik (itu yang
  mengisi `manager_user_id`), acc organizer (fixture hanya boleh memasangkan tim `approved`),
  lalu pembuatan fixture. `OfficiatingMatchTest::scene()` tidak bisa dipakai ulang: jalur
  registrasi sisi organizer **tidak** menyetel `manager_user_id`, dan justru kolom itulah yang
  seluruh fase ini bersandar padanya.

**Frontend fase 5** — `lib/api/team-matches.ts`, `components/team/lineup-editor.tsx`,
`participant/teams/[id]/matches/page.tsx` + `.../[matchId]/lineup/page.tsx`, dan
`LineupStatusBadge` di `components/shared/status-badge.tsx`.

- **Tidak ada kolom starter/cadangan yang bisa di-drag.** Rencana menyebut "dua kolom"; yang
  ditulis adalah satu daftar roster dengan tiga tombol per baris. Alasannya ada di kontrak
  full-list: pemain yang **tidak dikirim** akan dihapus, jadi "Tidak dibawa" harus bisa
  diucapkan, bukan disimpulkan dari tidak-mengklik-apa-pun. `components/ui/` juga tidak punya
  `checkbox.tsx`, jadi tidak ada komponen pilih-ganda yang bisa dipinjam.
- **Roster dikirim utuh, tidak disaring ke yang belum dinamai.** Sudah ditulis di docblock
  `payload()` sisi server: daftar yang menyusut selagi manajer bekerja adalah daftar yang tak
  bisa ia kembalikan orangnya.
- **Tombol "Kirim ke wasit" menyimpan dulu baru submit.** Tombolnya berbunyi kirim; mengirim
  lembar yang terakhir *tersimpan* alih-alih yang sedang dilihat adalah janji yang dilanggar
  tanpa error.
- **`editable` selalu dibaca dari server, tidak pernah diturunkan dari `status`.** Alasan yang
  sama dengan `effective_payment_method`: dua pembaca aturan yang sama akan berselisih, dan
  selisihnya tak terlihat.
- **Tautan "Buka jadwal" di halaman tim hanya untuk tim `approved`.** Tim `pending` belum punya
  fixture apa pun, dan tautan ke daftar kosong terbaca sebagai halaman rusak, bukan "belum".
- `LineupStatusBadge` merender `status_display` dari server dan cuma memutuskan warnanya —
  peta label di sisi klien akan jadi salinan kedua dari copy yang sudah punya satu pemilik.

---

## Fase 6 — Acc wasit

- [ ] `LineupApprovalController` di belakang `event.referee`
- [ ] `reject` wajib beralasan (`note` required) — penolakan tanpa alasan membuat manajer menebak
- [ ] Transisi selain dari `submitted` → 422; `approved` tidak bisa di-`reject` lagi
- [ ] Bandingkan `approve` dari `submitted` (200) vs dari `draft` (422)

---

## Fase 7 — Lembar susunan pemain

- [ ] `resources/views/pdf/lineup-sheet.blade.php` — **pass pertama**, user akan
      menggantinya. Tabel untuk layout, tanpa flexbox/grid, `DejaVu Sans`
- [ ] Isi: header event/kategori/jam/lapangan; dua tim bersisian; starter dipisah dari
      cadangan dengan sub-judul (bukan warna saja); ofisial bangku; tiga baris tanda tangan
- [ ] Gerbang: **422 kecuali kedua lineup `approved`** — permintaan user yang paling literal
- [ ] Rute kembar organizer di bawah `tenant`; satu controller, gerbangnya jangan disalin
- [ ] Unduhan frontend **wajib** lewat `apiClient` `responseType: "blob"` — token in-memory,
      `<a href>` polos akan 401
- [ ] Bandingkan satu tim di-acc (422) vs kedua tim di-acc (200)

---

## Catatan keputusan

**Password lewat email adalah penyimpangan yang disengaja.** Repo ini menyatakan posisinya
sendiri di `Admin\UserController::resetPassword`: *"Sampaikan password barunya lewat kanal
yang aman."* User memilih trade-off-nya secara eksplisit: default dikirim lewat email **dan**
wajib dirotasi di login pertama. Yang membatasi kerusakannya: rotasi ditegakkan di server
(bukan cuma UI), `different:current_password` melarang memasang ulang default itu, dan
password hanya pernah ditulis pada akun yang **dibuat** alur ini. Risiko sisa: passwordnya
mengendap sebagai teks biasa di kotak masuk dan log penyedia email selamanya. Perbaikan
bersihnya adalah tautan undangan bertanda tangan sekali pakai — infrastrukturnya sudah ada
(`PasswordResetController` + middleware `signed`) — dan dicatat sebagai pekerjaan lanjutan.

**Ukuran lineup tidak divalidasi.** Tidak ada sumber kebenarannya: `sports` tidak punya
`lineup_size`, `Catalog::positions()` itu katalog posisi bukan jumlah. Yang divalidasi:
kepemilikan pemain, duplikat, dan batas atas. Jumlahnya diserahkan ke wasit — itu memang
gunanya langkah acc. Celah yang diketahui, bukan kelalaian.

**Tanpa gerbang paket.** Sama seperti `event_personnel` hari ini yang sengaja ungated.
Menggerbanginya akan membuat seluruh fitur petugas jadi add-on berbayar yang tidak dibeli
organizer per event.
