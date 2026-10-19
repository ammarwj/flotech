# Kartu untuk ofisial (pelatih & bangku)

Kartu kuning/merah sekarang cuma bisa diberikan ke `players`. Pelatih yang
diusir wasit tidak punya tempat di database, jadi larangan bermainnya dicatat di
WhatsApp panitia. Fitur ini memberi bangku tabel stat sendiri dan menyalurkannya
ke setiap pembaca kartu yang sudah ada.

## Keputusan yang sudah final

- **Tabel sendiri: `official_match_stats`.** Bukan `player_id` nullable di
  `player_match_stats`. Invarian "ofisial bukan pemain" (CLAUDE.md, dan docblock
  `create_match_lineup_tables`) menyatakan satu tabel dengan pasangan kolom
  nullable **adalah** cara invarian itu bocor: tiap pembaca lalu harus ingat
  kolom mana yang terisi, dan yang lupa menaruh pelatih di leaderboard dengan
  nomor punggung. Efek sampingnya `PlayerStatService::leaderboard()`,
  `RubberController::validateLineup()`, `assertRosterSize()`, `syncDerivedName()`
  dan `CertificateService::recipients()` **secara struktural** tidak bisa
  melihat fitur ini.
- **Hanya kolom ber-role `yellow`/`red`.** Pelatih tidak mencetak gol; kolom gol
  untuk ofisial akan bocor ke cross-check skor dan ke leaderboard.
- **Kartu ofisial IKUT poin fair play** (tiebreaker klasemen) dan **ikut potongan
  jaminan** (`DepositService`): pelanggaran bangku adalah pelanggaran tim, dan
  "semua kartu dihitung kecuali kartu pelatih" adalah aturan yang tak seorang pun
  menduganya.
- **Payload disiplin pindah dari `player_*` ke `person_*`.** Satu bentuk dengan
  diskriminator `person_type`, presedens kolom `bank_accounts` yang dipakai
  ulang: lima surface merender tiga string + satu tipe, bukan bercabang. Tapi
  **bukan** `player_id` yang dipakai ulang untuk pelatih — itu kesalahan yang
  sama dengan menulis "Nomor rekening" di atas nomor HP GoPay.

## Progress

### Backend
- [x] Migrasi `official_match_stats` (match_id, team_id, team_official_id, stat_key, value)
- [x] Model `OfficialMatchStat` + relasi `GameMatch::officialStats()`
- [x] `DisciplineService` digeneralisasi ke "person": pemain + ofisial satu engine
- [x] `MatchStatService::snapshot()` menerbitkan bangku + kolom kartunya
- [x] `MatchStatService::replace()` menulis kedua tabel, menolak non-kartu untuk ofisial
- [x] `StandingService::fairPlayPoints()` menjumlahkan kedua tabel
- [x] `DepositService::cardsByTeam()` menjumlahkan kedua tabel
- [x] `MatchReportService` — daftar kartu di PDF memuat ofisial
- [x] `PublicEventController::matchStats()` — detail laga publik memuat ofisial
- [x] `LineupService` — ofisial terskors ditolak di `sync()` + `submit()`
- [x] `MyTeamMatchController::payload()` — `bans` memuat ofisial

### Frontend
- [x] `web/types/api.ts` — `person_*` + `person_type`, `LineupBan`
- [x] `match-stats-editor.tsx` — blok ofisial, kolom kartu saja
- [x] `discipline-table.tsx` — baris ofisial (jabatan, bukan nomor punggung)
- [x] `match-discipline-notice.tsx` — nama ofisial di peringatan kartu laga
- [x] `lineup-editor.tsx` — ofisial terskors dicoret & tak bisa dibawa
- [x] `match-detail-dialog.tsx` — kartu ofisial di detail laga publik

### Test
- [x] `OfficialCardsTest` — banding pemain vs ofisial dalam satu kategori
- [x] fair play & deposit: banding kartu pemain vs kartu ofisial dengan bobot sama
- [x] lineup: ofisial terskors ditolak, pemain di tim yang sama tetap lolos
- [x] leaderboard tidak pernah memuat ofisial
