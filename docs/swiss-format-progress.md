# Progres: format Swiss (Swiss Round)

Rencana: `~/.claude/plans/tambahan-format-untuk-swiss-indexed-dewdrop.md`

Keputusan yang sudah final (jangan dibuka lagi): ronde demi ronde manual, hindari
rematch, tiebreaker Buchholz, `matches.stage = 'swiss'`. Tidak ada migrasi.

## Tahap

- [x] 1. Berkas pelacak ini
- [x] 2. `Engines::FORMATS += swiss`, `TIEBREAKERS += buchholz`, dua baris `ConfigOptionSeeder` (buchholz `sort_order` paling akhir), retarget `CatalogTest` ke `ladder`
- [x] 3. `HybridConfig`: `swiss_rounds` (readonly + `fromArray()` + validasi), `swissRoundCount()`
- [x] 4. `App\Support\SwissPairing` + `SwissPairingTest` (murni, tanpa DB)
- [x] 5. `StandingService`: `scopeTableStages()` di tiga pembaca, perbaikan bye `pendingCounts()`, `buchholz()`, `byeAwards()`, arm `compareBy()` + `BuchholzTest`
- [x] 6. `ScheduleService`: `generateSwissRound()`, helper swiss*, param ke-5 `?int $round` di `applySchedule()` + `SwissScheduleTest`
- [x] 7. `App\Services\SwissService` (state / blocker / pairingOrder)
- [x] 8. `MatchController` (arm `swiss` di `generate()`, `swissState`, `generateSwissRound`, `destroySwissRound`) + tiga rute + `SwissFormatTest` + `SwissByeTest`
- [x] 9. Frontend: `types/api.ts` → `lib/{hybrid,swiss,bracket,scoring,api/matches}.ts` → komponen → halaman jadwal + publik + docblock config-options
- [x] 10. `CLAUDE.md`: section "Pola: format Swiss (ronde demi ronde)"

## Catatan saat jalan

- Service docker compose untuk artisan adalah **`api`**, bukan `app` (bagian
  Verifikasi di rencana menulis `exec app` — salah). `docker compose exec -T api php artisan test`.
- Baris katalog `buchholz` menambah satu entri ke daftar tiebreaker default
  **setiap** konteks, jadi tiga array harapan di `StandingsFormatTest` dan dua
  `assertCount` di `CatalogTest` ikut naik. Itu disengaja; posisi paling akhir
  (di belakang `drawing_lots`, yang sudah total order) yang membuatnya tidak
  me-rank ulang apa pun.
- `pendingCounts()` sekarang ikut memfilter `home_team_id`/`away_team_id`
  non-null. Baris bye (finished, skor null) cocok dengan predikat "belum
  dimainkan", jadi tanpa ini tim yang kebagian bye pending selamanya dan
  `markUndecided()` bungkam permanen — bug yang sudah laten untuk bye knockout.
- Fixture `BuchholzTest` butuh **enam** tim, bukan empat: dengan empat, yang kalah
  di separuh A adalah yang menang di separuh B, jadi kekuatan jadwal kedua pihak
  selalu sama dan tidak ada yang bisa dibuktikan. `head_to_head` juga sengaja
  tidak dipakai di urutan uji — C dan F ikut 3 poin, jadi blok tiebreaker-nya
  empat tim dan head to head memisahkan A/B atas laga yang tidak diuji.
- `applySchedule()` **mematikan `spread` saat `$round` dikirim**. Spread ada untuk
  menebar seluruh kompetisi di jendela event; diminta untuk satu ronde ia
  melakukan kebalikannya — ronde yang butuh dua hari ditaruh di hari pertama dan
  hari terakhir, lalu ronde sesudahnya ("sehari sesudah hari terjadwal terakhir")
  mendarat di luar `end_date`. Satu ronde = satu blok hari berurutan.
- `swissRoundStart()` **men-clamp ke `end_date`**: event satu hari tidak punya
  tempat untuk "hari berikutnya", jadi rondenya berbagi hari alih-alih dijadwalkan
  di luar rentang yang diketik organizer. `SwissScheduleTest` membandingkan jendela
  panjang (ronde 2 = 08-02) dengan jendela satu hari (ronde 2 = 08-01).
- `SwissService::exhausted()` **menanyakan pairer**, bukan menghitung pasangan:
  "semua pasangan sudah bertemu" dan "semua pasangan yang masih bisa dijangkau
  pairer sudah bertemu" beda pertanyaan begitu bye dikeluarkan dari urutan.
  `pair()` melaporkan `rematches` alih-alih melempar, jadi non-zero = habis.
- Rute organizer untuk update hasil adalah `organizations/{org}/matches/{match}`
  (bukan nested di bawah event) — `SwissFormatTest` sempat 404 karena itu.
- Fixture `SwissByeTest` yang menguji perpindahan bye **wajib override
  `swiss_rounds`**: lima peserta menurunkan 3 ronde, sementara aturan `max − min ≤ 1`
  baru terlihat pada ronde ke-4. Dan `max − min` dihitung atas **seluruh** peserta
  (yang belum pernah bye = 0), bukan atas baris bye yang ada.
- Suite penuh hijau sesudah tahap 8: 788 passed / 4882 assertions.
- Tab publik tidak perlu disentuh: `categoriesFor()` di halaman publik menggerbang
  bracket pada `isKnockout || isHybrid` dan klasemen pada `! isKnockout`, jadi Swiss
  sudah dapat tab Klasemen dan sudah tertahan dari tab Bracket.
- `refreshEventData()` di halaman jadwal ikut meng-invalidate key `["swiss", …]`:
  yang membuka ronde berikutnya adalah **konfirmasi hasil**, bukan pembuatan ronde,
  jadi gate wajib ditanya ulang sesudah tiap tulisan di halaman itu.
- Error lint yang ada (`participant/teams/[id]`, `.../lineup`, `registration-form`)
  sudah ada sebelum tahap ini; tidak ada di berkas yang disentuh. `tsc --noEmit` bersih.
