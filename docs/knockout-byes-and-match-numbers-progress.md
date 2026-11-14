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
- **Bagan ganjil membongkar tata letak kolom bagan, dan itu bug tersendiri.**
  `.bkt-body` dulu `grid-auto-rows: 1fr` — tiap kolom membagi tingginya **rata**,
  yang eksak hanya selama tiap babak membelah bersih. Di 18 → 9 → 5 → 3 → 2 → 1
  tidak: 9 tidak terbagi rata jadi 5, jadi kartu melenceng dari pasangan
  pengumpannya dan errornya **menumpuk ke kanan** (terukur 278px di babak
  keempat, garis masuk jauh di atas kartunya). Sekarang **semua kolom memakai
  grid baris yang sama** (`--bkt-rows` = jumlah slot babak 1) dan tiap kartu
  me-`span` baris milik pengumpannya. Di bagan pangkat dua keduanya identik —
  merentang 2^col dari 2^n baris **adalah** pembagian rata.
- **Final butuh satu hal lagi: geseran dalam banding.** Kedua pengumpannya bisa
  beda tinggi (semifinal 16 baris vs 2), jadi titik temu garisnya bukan di tengah
  bandingnya sendiri. `cardShift()` menghitungnya, dan CSS-nya memakai **`top`
  persen** — bukan margin/padding persen (resolve ke **lebar** containing block)
  dan bukan `translateY` persen (resolve ke tinggi **elemen**-nya sendiri). Dua
  percobaan pertama gagal persis karena itu, keduanya diam-diam.
- **`.bkt-cell-third` menimpa `.bkt-cell`**, jadi perbaikan apa pun di `.bkt-cell`
  tidak berlaku di sel final sampai keduanya dibuat sejalan. Gejalanya: angka
  selisih **tidak berubah sama sekali** sesudah perbaikan — tanda aturannya tidak
  pernah menyala, bukan tanda perhitungannya salah.
- Verifikasi bukan dari screenshot: `getBoundingClientRect()` tiap kartu vs titik
  tengah pengumpannya. **Bagan ganjil 0px, bagan pangkat dua 0px.** Mata tidak
  bisa membedakan meleset 30px dari lurus; angka bisa.

## Bye tidak digambar sama sekali (seperti bracket cetak)

Keluhan berikutnya: bagan penuh kotak "vs Bye". Itu memang bukan cara bracket
dicetak — tim yang dapat bye digambar **sekali**, di babak tempat ia benar-benar
masuk, dan cabangnya mulai lebih ke kanan.

> **Invarian: kartu bye tidak dirender, SELNYA tetap ada.** Sel itu memiliki
> baris di grid bersama yang dipakai seluruh kolom (`--bkt-rows`), jadi
> menghapus selnya akan menggeser **setiap** kartu di atasnya keluar dari garis
> pengumpannya. Yang dibuang hanya kartunya (`.bkt-cell-blank`, `min-height: 0`)
> plus konektor yang keluar/masuk ke sana — garis yang berangkat dari sel kosong
> adalah garis yang mulai di udara.

> **Dua bentuk "bye", dan keduanya harus disembunyikan.** `isBye(m)` = baris
> yang **sudah** ditulis bye (satu tim duduk, `status: finished`). Tapi di bagan
> ragged ada bentuk kedua: slot yang **belum** ada isinya dan pengumpan
> sebelahnya memang tidak pernah ada — server sudah mengkaptionnya `"Bye"`.
> Yang ini bukan `isBye()` (statusnya masih `scheduled`), jadi butuh
> `isDeadSide(m, side)` di `web/lib/bracket.ts` yang membaca caption server,
> bukan menyusun ulang aturannya. Tanpa itu `P32` tetap berbunyi
> "Pemenang P27 vs Bye". Ujilah dengan **membandingkan** bagan padded (bye
> tertulis) dan ragged (bye belum tertulis) — satu bentuk saja lolos walau
> bentuk lain masih mencetak "Bye".

- **`cardShift()` membuang bye dari rata-ratanya.** Kartu duduk di titik temu
  garis yang **digambar**, jadi kartu yang satu pengumpannya bye harus sejajar
  dengan pengumpan yang nyata — itu yang membuat konektornya garis lurus, bukan
  siku yang menjulur ke laga yang tidak ada di bagan.
- **Efek sampingnya menjawab keluhan awal**: tanpa kartu bye, mode `first` pun
  rapi. 36 tim di bagan 64 = 4 laga di kolom 1, 28 tim langsung tampil di kolom
  2 — tidak ada lagi 28 kotak "vs Bye".

## Dibatalkan: mode `late` dan penyembunyian kartu bye

Dua keputusan dicabut setelah dilihat di layar sungguhan. Keduanya **benar
secara data** — garis presisi 0px, bagan sesuai gambar — dan tetap dibuang
karena bacaannya lebih buruk. Catatan ini ada supaya tidak dibangun ulang.

- **`bracket_config.bye_round` dihapus seluruhnya** (konstanta, validasi,
  parameter `roundSlots()`, field di `event-form.tsx`, tipe di `types/api.ts`,
  key whitelist di `hybrid.ts`, prop `byeRound` di `schedule-settings-dialog`).
  Satu bentuk = tidak ada setelan yang harus dijaga sejalan antara dua jalur.
  `KnockoutByeRoundTest` dihapus; `MatchNumberTest::test_it_captions_a_ragged_draw`
  ditulis ulang jadi `test_it_captions_a_large_padded_draw` (36 tim di bagan 64).
- **Kartu "vs Bye" dikembalikan.** Menyembunyikannya meniru bracket cetak, tapi
  di layar justru jadi aneh — cabang yang tiba-tiba mulai di kolom kedua tanpa
  penjelasan. `isDeadSide()` dan `.bkt-cell-blank` dihapus, `cardShift()` kembali
  menghitung semua pengumpan.

**Yang TETAP dipertahankan** meski generation tidak lagi membuat bagan ragged,
karena baris ragged masih ada di DB: `bracket_slots`, `roundSlotsFromFirst()`,
`isLoneFeeder()`, dan seluruh perbaikan tata letak kolom bagan (grid baris
bersama + `cardShift()`), yang **juga** memperbaiki bagan pangkat dua.

## Bye tetap ada, tapi tidak diketik / dijadwalkan / digambar

Keluhan terakhir: 36 tim berarti organizer mengetik 28 baris "vs Bye", lalu
jadwalnya tertimbun 28 kartu kosong. Catatan penting yang perlu diluruskan
lebih dulu: **36 tim tidak bisa tanpa bye**. Babak 2 harus berisi 32 tim; 4 laga
menghasilkan 4 pemenang, 28 tim sisanya wajib lewat tanpa main. Itu aritmetika.
Bracket HQ pun begitu — ia cuma tidak menggambarnya.

Jadi yang dihapus penampakan & pengetikannya, di tiga tempat:

- **Form seeding** hanya menanyakan `peserta − slot` laga (36 tim → 4 baris),
  sisanya daftar baca-saja "Langsung ke babak berikutnya".
  `BracketSeeding::fill()` menempatkannya di server.
- **Jadwal organizer** memfilter bye (`playableMatches`) lewat satu variabel
  `played` yang dibaca section, `groupByDate`, dan pencarian sekaligus.
- **Bagan** tidak menggambar kartu bye; selnya tetap (memiliki baris di grid).

### Bug yang ditemukan sambil jalan

`fill()` hanya bisa memakai slot **kosong**, jadi payload dengan terlalu sedikit
pasangan menelantarkan tim tanpa error. 4 tim di bagan 4 (2 slot, nol ruang bye)
mengirim 1 pasangan → dulu 201 dengan satu tim hilang. Sekarang 422.
**Yang menangkapnya** assert yang menjumlahkan total tim terpasang di babak 1,
bukan assert "bracket terbentuk" — yang itu hijau penuh.

### Test yang berubah artinya

`unplacedTeams()` tidak lagi menolak; ia sekarang **sumber daftar bye**. Tiga
test yang menegakkan aturan lama ditulis ulang: dua di `ManualSeedingTest`
(+ satu test baru untuk batas minimum), satu di `HybridFormatTest`. Dua kali
fixture-nya salah pilih ukuran lapangan — 4 tim dan 4 qualifier sama-sama tidak
punya ruang bye, jadi perlu 5 tim / 3 qualifier.

## Satu grid untuk seluruh bagan (bukan satu grid per kolom)

Dua keluhan sesudah bye disembunyikan: nomor melompat (P1–P4 lalu P33) dan
bagannya berantakan. Keduanya akibat yang sama, tapi perbaikannya berbeda.

- **Penomoran**: bye tidak lagi diberi nomor (`number` = null). Nomor harus
  menghitung apa yang **terlihat**; kalau tidak, "P33" di program acara menamai
  pertandingan ke-5 lewat posisinya yang ke-33. Ada assert yang memeriksa seluruh
  nomor tercetak = `range(1, n)` — assert satu nomor saja tidak menangkapnya.
- **Tata letak**: perombakan. Versi sebelumnya memberi tiap kolom grid sendiri
  lalu membagikan daftar track yang sama — yang **tidak menyejajarkan apa pun**,
  karena track menghitung tinggi dari isi grid-nya sendiri. Meleset 263px.

### Yang dipelajari, supaya tidak diulang

1. **Grid terpisah tidak bisa disejajarkan dengan menyamakan daftar track.**
   Gejalanya: angka selisih **tidak berubah** sesudah "perbaikan" — tanda
   mekanismenya tidak pernah menyala.
2. **`repeat(n, 1fr)` menyamakan semua baris setinggi kartu tertinggi.** 28 baris
   kosong → 3648px untuk 4 laga. Track dibangun per baris: `1fr` kalau ada kartu,
   `auto` kalau tidak. Hasil: 2329px.
3. **`cardShift()` dihapus.** Aritmetika baris mentok di 20px karena bernalar
   dalam baris sementara browser bernalar dalam piksel. `drawnSpan()` rekursif
   menyerahkannya ke grid.
4. **Selektor konektor tidak bisa struktural lagi** — tidak ada elemen per-kolom.
   Diganti kelas eksplisit dari komponen: `.bkt-feeds`/`.bkt-joins`/`.bkt-fed`.
5. Ronde dibungkus `React.Fragment`; elemen pembungkus akan jadi grid item dan
   mengembalikan persarangan yang justru dibuang.

### Dua detail konektor sesudah perombakan

Keduanya tampil ke user sebagai "bagannya tidak rapi":

6. **`.bkt-fed` harus menanyakan apakah ada pengumpan yang digambar.**
   `col > 0 && !blank` menggambar lead-in untuk kartu yang kedua pengumpannya
   bye — garis menjulur ke kolom kosong (terlihat di P6/P7/P8 pada draw 36).
7. **`drawnSpan()` memakai GABUNGAN rentang pengumpan**, bukan "baris tengah"
   tiap pengumpan. Versi baris-tengah membulatkan ke bilangan bulat dan meleset
   6px di tiap kartu bernama dua baris, 59px kalau satu ronde mencampur tinggi
   kartu. Gabungan rentang membuat kedua ujung memakai batas yang sama dengan
   pengumpannya, jadi titik tengahnya berimpit berapa pun tinggi barisnya.

Sisa simpangan ≤53px ada di tiga kartu babak akhir, dan itu batas struktural:
kartu terpusat di rentang barisnya sementara kedua pengumpan terpusat di
sub-rentang masing-masing — sama hanya kalau kedua sub-rentang sama tinggi.
Menutupnya butuh pengukuran runtime (ResizeObserver), karena tinggi kartu baru
diketahui sesudah render. Tidak terlihat di layar; dibiarkan sebagai utang.
8. **Panjang vertikal harus diukur runtime.** `height: 100%` (tinggi sel) lewat
   106px dari kartu pasangannya begitu baris bye kolaps tidak merata.
   `--bkt-drop` diisi `ResizeObserver`; pasangan dicari lewat `data-pair`
   karena `nextElementSibling` menyeberang ronde di grid tunggal. Hook wajib di
   atas early return (`react-hooks/rules-of-hooks` yang menangkapnya).
   Verifikasi: ujung vertikal vs `cardMid` pasangan → 106px jadi 2px.
9. **`--bkt-drop` diukur antar SEL, bukan antar kartu, dan tanpa pembulatan.**
   Stub digambar di `top: 50%` **sel**; kartu terpusat di selnya hanya sampai
   batas pembulatan, jadi mengukur dari mid-kartu meleset ~1px dan tampil
   sebagai takik di siku (gambar user: P2 → P19). Ukur dari sumber yang sama
   dengan yang digambar. Verifikasi: ujung vertikal = ujung stub pasangan,
   587.38 = 587.38; sisa ±1.00px itu overshoot setengah garis yang disengaja
   supaya sikunya persegi.
10. **Paruh bawah tanpa pasangan kehilangan seluruh penghubungnya.** Vertikal
    digambar paruh atas; kalau paruh atas bye, stub (18px) dan lead-in (38px)
    tetap digambar tapi 56px di antaranya kosong. `.bkt-solo` merentangkan stub
    ke `--bkt-gap`. Perlu `.bkt-cell.bkt-solo` — spesifisitas setara kalah.

### Cara menemukannya (tiga ronde pengukuran gagal lebih dulu)

Metrik geometri DOM bilang "semua rapat ≤2px" sementara user masih melihat
putus, tiga kali berturut-turut. Yang akhirnya berhasil: **memindai piksel
screenshot**, mencari ujung segmen garis yang bersentuhan dengan ruang putih
dan tidak dekat tepi kartu. Dua jebakan di pemindai itu sendiri:
- Warna border kartu `(229,231,235)` hampir sama dengan garis `(209,213,219)`;
  menghitung keduanya sebagai "garis" menutup celah secara palsu.
- Crop yang dipakai mengukur harus diambil ulang tiap kali, karena Playwright
  mengosongkan `test-results/` dan koordinatnya bergeser.

Dan yang paling memakan waktu: **saya memotret bracket yang salah.** Ada tiga
bracket 36-tim di DB lokal; yang user lihat ada di event `panra-cup` (yang
akunnya bukan anggota saya), bukan di `flobamora-event`. Bentuk babak-2-nya
beda — slot `-T`, `T-`, `--` — dan harus direplikasi dulu lewat `fill()`
sebelum bug-nya bisa muncul sama sekali.

