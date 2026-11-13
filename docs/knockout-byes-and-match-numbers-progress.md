# Progres: bye KO ganjil & penomoran partai

Dua masalah yang dilaporkan dari bagan Panra Cup 2026 (36 tim, kategori
SMA/SMK/MA), akarnya berbeda dan dikerjakan terpisah.

## Masalah 1 — bentuk bye untuk jumlah tim non-pow2

`ScheduleService::generateKnockout()` memadatkan field ke pangkat dua berikutnya
lalu menaruh **seluruh** bye di slot paling depan babak 1. 36 tim → bracket 64,
28 bye: cuma 8 tim yang main di babak 1 dan 28 tim langsung duduk di babak 2.
Bagan Panra sebaliknya — 36 tim semua main di babak 1 (18 laga), dan bye baru
muncul di babak berikutnya karena 9 pemenang tidak bisa dibagi dua.

Keputusan: **dua bentuk, dipilih organizer** lewat `bracket_config.bye_round`.

- `first` (default, perilaku lama) — bracket pangkat dua, bye di babak 1.
- `late` — tiap babak memasangkan sebanyak mungkin, sisa ganjil dapat bye di
  babak itu. 36 → 18 → 9 → 5 → 3 → 2 → 1.

> **Invarian: panah antar slot tidak berubah.** Pada bentuk `late`, bye selalu
> slot **terakhir** sebuah babak, jadi aturan "anak order 2k & 2k+1 → induk
> order k" tetap berlaku persis seperti bracket pangkat dua. Itulah yang membuat
> `advanceWinner()`, `parentOf()`, `clearDownstream()`, dan `bracketDepths()`
> **tidak disentuh sama sekali**. Ujilah dengan membandingkan rantai propagasi
> di bracket `late` 6 tim dengan bracket `first` 8 tim; assert "bagan terbentuk"
> saja akan lolos walau pemenang babak 1 mendarat di slot yang salah.

> **Invarian: tinggi bracket tidak lagi bisa menurunkan jumlah slotnya.**
> `bracketSlotCount()` memakai `2^maxRound / 2`, yang hanya benar untuk pohon
> pangkat dua — pada bentuk ragged ia salah hitung dan laga tambahan
> (`storeManual()`, berbagi `stage null, round 1`) akan dikira slot bracket lalu
> ikut diundi ulang. Karena jumlah slot babak 1 **tidak bisa diturunkan** dari
> babak di atasnya (slot_r ∈ {2·slot_{r+1}−1, 2·slot_{r+1}}, ambigu satu),
> ia disimpan: kolom baru `event_categories.bracket_slots` (nullable). Null =
> baris lama = pakai rumus pangkat dua, jadi bracket yang sudah jalan tidak
> berubah perilakunya sama sekali.

## Masalah 2 — bagan tanpa penomoran partai

Slot kosong tidak bisa dirujuk: bagan mencetak "TBD", kartu jadwal mencetak
"TBD vs TBD · menunggu hasil sebelumnya". Organizer tidak punya cara menulis
jadwal untuk laga yang timnya belum ada.

Keputusan: **nomor diturunkan di server, per kategori**, bukan kolom tersimpan.

> **Invarian: nomor & asal slot diturunkan, tidak pernah disimpan, dan tidak
> pernah disusun ulang klien.** Nomornya fungsi dari `(stage, round, order)` —
> urutan yang sama dengan `orderedMatches()` — jadi kolom tersimpan akan jadi
> penulis kedua yang berselisih begitu ada laga tambahan ditambah atau bracket
> di-generate ulang. Dan label "Pemenang P12" dihitung di server, bukan dengan
> mengirim nomor mentah lalu membiarkan kartu menjodohkan sendiri slot dengan
> pengumpannya: itu bug `effective_payment_method` lagi, dan di sini pembaca
> keduanya ada **lima** (bagan, kartu jadwal organizer, kartu publik, dialog
> detail, PDF). Ujilah dengan membandingkan slot babak 2 yang satu pengumpannya
> sudah selesai: satu sisi wajib bernama tim, sisi lain "Pemenang Pn".

## Tahap

- [x] 1. Berkas pelacak ini
- [x] 2. Migrasi `event_categories.bracket_slots` + `HybridConfig::byeRound` (`first|late`) + validasi
- [x] 3. `ScheduleService::generateKnockout()` bentuk `late` + `bracketSlotCount()` membaca kolom + `KnockoutByeRoundTest`
- [x] 4. `App\Services\MatchNumberService` (map nomor + label asal slot) + `MatchNumberTest`
- [x] 5. `MatchResource` terbitkan `match_no`, `home_source`, `away_source`; attach di tiap pintu jadwal
- [x] 6. Frontend: `types/api.ts` → `lib/bracket.ts` → `bracket-view`, kartu jadwal organizer, `public-match-card`, `match-detail-dialog`
- [x] 7. Frontend: pilihan "Letak bye" di `bracket-seed-editor` / `schedule-settings-dialog`
- [x] 8. Seed lokal turnamen duplikat Panra (36 tim) di org flobamora, verifikasi di layar
- [x] 9. `CLAUDE.md`: section pola

## Catatan saat jalan

- Service docker compose untuk artisan adalah **`api`**:
  `docker compose exec -T api php artisan test`.
- **Letak bye di mode `first` tidak boleh ikut dipindah.** Sempat ditulis
  "bye selalu di slot belakang" untuk kedua mode; `BracketSlotEditTest`
  menamai posisi itu (slot 0 & 1 adalah bye untuk 6 tim di bracket 8) dan tiga
  testnya jatuh. Bracket pangkat dua punya sibling di tiap slot, jadi bye boleh
  di mana saja; yang ragged **wajib** di slot terakhir.
- **"Sibling tidak ada" bukan uji yang benar untuk walkover babak lanjutan.**
  Versi pertama menanyakannya ke DB (`where order = sibling → exists`), dan itu
  menyalakan walkover di pohon hybrid yang dibuat tangan oleh `DisciplineTest`
  (semifinal order 0, tanpa order 1) — kartunya lalu dianggap sudah dijalani di
  laga yang statusnya berubah jadi `finished`. Jawabannya diturunkan dari
  `bracket_slots` yang **tercatat** (`isLoneFeeder()`): bracket hybrid pangkat
  dua dan pohon buatan tangan sama-sama menjawab false, jadi keduanya persis
  seperti sebelumnya.
- Ujung rute hasil laga adalah `PATCH organizations/{org}/matches/{match}`
  dengan `status` **wajib**, konfirmasinya `PATCH .../confirm` ber-`confirmed`.
- **`knockoutRoundLabel()` ikut terdampak dan nyaris terlewat.** "N Besar"
  dihitung `laga × 2`; babak ragged 5 laga memuat 9 tim, jadi headingnya menulis
  "10 Besar". Sekarang dinamai dari jumlah laga babak di bawahnya. Tiga pemanggil
  (`phaseLabel`, `buildMatchSections` ×2) plus `BracketView` wajib mengirimnya.
- **Prop `size` di `BracketSeedEditor` jadi `slots`.** `size / 2` tidak bisa
  mengeja 18 slot pembuka; dua pemanggilnya sudah tahu angkanya masing-masing.
- `PanraCupSeeder` (`db:seed --class=PanraCupSeeder`) membuat org
  `flobamora-event` + event `panra-cup-2026` berisi **dua** kategori: 36 sekolah
  yang sama, satu `late` satu `first`. Membandingkan keduanya di layar itulah
  gunanya — masing-masing terlihat wajar kalau dilihat sendirian.
- Verifikasi di layar: bagan ragged 36 → 18 → 9 → 5, nomor P1–P39, babak 2 penuh
  "Pemenang P1 vs Pemenang P2", dan slot ber-pengumpan tunggal berbunyi "Bye".
