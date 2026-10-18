# Fun run: audit surface & penyesuaian

Cabang `fun_run` (`sports.scoring = 'none'`) sudah punya gate inti — `isRaceEvent()`
di `web/lib/race.ts`, `Catalog::isCompetitive()` di server, dan `RaceRouteGuard`
di empat rute. Yang tersisa adalah surface yang belum ikut bertanya. Daftar ini
hasil sapuan seluruh repo, bukan cuma dua hal yang dilaporkan.

## Prinsip yang dipakai

- **Satu pembaca.** Semua gate frontend lewat `lib/race.ts`; tidak ada komponen
  yang mengeja `scoring === "none"` sendiri. Server lewat `Catalog::isCompetitive()`.
- **Sembunyikan tombol = setengah pekerjaan.** Tiap rute tetap menjawab URL-nya
  sendiri, jadi halaman yang tombolnya hilang wajib punya `RaceRouteGuard` —
  presedens `/[orgSlug]/[eventSlug]/register`.
- **Keterangan keadaan dibiarkan, yang mengajak bertindak digerbang** (pola yang
  sudah ditulis untuk `online_registration`).

## Dikerjakan

- [x] `lib/race.ts` — tambah `isRaceSport(sport)` untuk katalog; `isRaceEvent()`
      delegasi ke situ. Form memilih cabang dari dropdown sebelum event ada.
- [x] `event-form.tsx` — kartu **Jaminan** (jaminan per tim, lomba tak punya tim),
      input **Lapangan** (cuma dibaca `ScheduleService`), blok kategori di
      **Pratinjau** ("0 kategori" atas event yang kategorinya di tempat lain).
      `rules_config.deposit` absen dari payload, bukan nol.

## Tiket penonton — lomba tidak punya venue bergerbang

Tiket itu tiket **penonton** untuk venue yang bisa ditutup. Fun run jalan di
jalan/taman, dan pelarinya sudah membawa QR-nya sendiri (`race_entries.qr_code`,
dipindai di `/race-scan`). Jadi ini bukan fitur yang hilang, cuma fitur yang
tidak punya subjek.

- [x] `TicketCategoryController::ensureTicketsEnabled()` — tolak cabang
      non-kompetitif lewat `Catalog::isCompetitive()`, yang docblock-nya memang
      menyebut dirinya landing place gate berikutnya. **422 tanpa
      `errors.feature`**, beda dari 403 paket di bawahnya: `isPlanLimitError()`
      akan menelannya jadi toast "naikkan paket" untuk jawaban yang tak bisa
      diubah pembelian.
- [x] `/organizer/events` — tombol **Tiket** ikut `!isRace`.
- [x] `/organizer/events/[id]/tickets` + `tickets/buyers` — `RaceRouteGuard`
      `requires="competition"`, query dimatikan, gate paket dilewati untuk lomba
      (keduanya benar untuk lomba berpaket tanpa tiket; yang paket tak bisa
      menolong).
- [x] `/organizer/tickets` (lintas event) — event lomba keluar dari daftar, dan
      **empty state-nya bercabang**: org yang semua event-nya lomba punya event,
      jadi "Belum ada event" akan bohong dan menyuruhnya beli kredit.

## Bug kedua: cabang bisa dipindah membawa kategori tiketnya

`sportTypeFor()` memeriksa `race_entries`/`teams`/`matches` — **bukan**
`ticket_categories`. Itu satu-satunya dari empat yang bisa terisi tanpa peserta
sama sekali, jadi event sepak bola yang menyiapkan tiket di hari pembuatannya
masih bisa dipindah ke `fun_run` dan membawanya: terjual lewat pintu yang cabang
barunya ditolak, dan tak terlihat di surface lomba mana pun.

- [x] Arm keempat di `sportTypeFor()` + pesannya diperbarui.
- [x] Test `test_sport_type_is_frozen_by_a_ticket_category_alone` —
      dibandingkan dengan draf tanpa tiket, karena assert penolakan sendirian
      lolos walau guard-nya membekukan semua event.

## Halaman edit

- [x] Tombol **Jadwal & Klasemen** ikut `!isRace` — tidak ada fixture.
- [x] Dialog hapus menyebut "N kategori kompetisi"; untuk lomba angkanya selalu 0
      sementara kategori lombanya nyata dan ikut terhapus. Dinamai tanpa angka.
- [x] Tombol **Formulir Pendaftaran** **tetap** — lihat bagian di bawah.

## Bug: formulir pendaftaran lomba tidak bisa diatur

`PublicRaceController` membaca `RegistrationForm::forEvent()` — `assertFormComplete()`
memvalidasi `teamFields` yang wajib, `cleanAnswers()` memfilter atas key yang sama,
dan form publik di `/[orgSlug]/[eventSlug]/race` merender `schema.team_fields` dan
`schema.team_documents`. Tapi `/registration-form` digerbang `requires="competition"`,
jadi organizer lomba **tidak bisa membuka editor dari field yang formulirnya pakai**.

- [x] `RaceRouteGuard` dibongkar; halaman terbuka untuk lomba dengan **hanya**
      section tim (field + dokumen), diberi label "Peserta" karena "Tim" salah
      kata untuk pelari tunggal. Key tersimpan **tidak** diganti — kolom
      schema-nya sama dan rename akan menelantarkan jawaban yang sudah terkumpul.
      Empat section roster tetap tersembunyi: pelari tak punya roster/bench, dan
      `PublicRaceController` memang tidak membacanya.

## Kartu katalog publik

`PublicEventCard` menulis tiga angka yang semuanya kompetisi: `approved_teams_count`
("0 tim" atas lomba yang kuotanya habis), `categories_count`, dan rentang biaya dari
`event_categories.registration_fee` — sementara harga lomba hidup di
`race_periods`/`race_categories` dan berubah per periode. Lomba yang terjual habis
memasang "0 tim" dan "Gratis" sekaligus.

- [x] Ketiganya disembunyikan untuk lomba. Tidak diganti angka lomba: payload
      katalog tidak punya agregatnya, dan harga per periode tidak punya satu
      angka. Pill "Pendaftaran dibuka" **tetap** — itu
      `registrationIsPubliclyOpen()`, sama benarnya untuk lomba.

## Sertifikat

- [x] `/organizer/certificates/generate` — event lomba keluar dari dropdown:
      `recipients()` membaca `teams` + `players` dan mengembalikan dua daftar
      kosong, yang terbaca sebagai gagal memuat, bukan sebagai jawaban.
      `selectedEvent` ikut dicari di daftar terfilter supaya id lomba tak bisa
      jadi pilihan dari sumber lain. Sertifikat untuk pelari = tipe penerima
      ketiga di service itu; fitur, bukan gate, dan belum diminta.

## Sengaja TIDAK disentuh

- **`/stats`** — analitik pengunjung halaman publik; lomba punya halaman publik.
- **`/payments`** — sudah menangani `kind: "race-entry"`.
- **Metode pembayaran** di form event — lomba memang menjual entri.
- **Disiplin / Babak / Susunan** — gate katalognya sudah membaca `=== 'goal'`
  positif, jadi `'none'` tidak pernah lolos.
- **Badge status** di halaman publik — keterangan keadaan, bukan ajakan bertindak.
- **`sportTypeFor()`** — sudah menolak ganti cabang begitu ada entri/tim/laga
  (dan sekarang kategori tiket).

## Halaman Peserta Lomba (organizer)

Tiga perbaikan setelah dipakai dengan data sungguhan.

- [x] **Detail per pendaftar.** Datanya sudah ada di `RaceEntryResource` dan di
      ekspor, tapi tidak pernah di layar — untuk melihat NIM satu orang,
      organizer harus mengunduh spreadsheet. `RaceEntryDetail` membukanya di
      bawah barisnya sendiri. Lima blok dengan lima bentuk berbeda karena isinya
      lima jenis; consent jadi checklist, bukan grid label-nilai, karena
      labelnya kalimat penuh dan nilainya timestamp pendek — dirender sebagai
      pasangan biasa, kalimat terpanjang justru dapat tipe terkecil.
- [x] **Periode & kategori pindah ke halaman Kelola Event** (`RaceSetupPanel`,
      pola `EventPlanPanel`). Sempat dibuat jadi tab di halaman Peserta Lomba;
      itu memperbaiki urutannya tapi bukan tempatnya. Menyiapkan **apa yang
      dijual** adalah pekerjaan yang sama dengan menamai event dan memilih
      tanggalnya; "siapa yang mendaftar" pertanyaan lain yang ditanya di hari
      lain. Yang mencari daftar harga harus menebak bahwa ia ada di balik tombol
      bernama orang-orang yang membelinya.
      Keduanya tetap **satu komponen**, bukan dua: harga milik periode, jadi
      kategori tidak bisa disimpan sebelum ada periode — tombolnya disabled
      dengan alasan di layar, dan server menolaknya juga. Dipisah, aturan itu
      harus dioper sebagai prop.
      Kartu rambu di `event-form.tsx` kini **hanya muncul saat event dibuat**:
      sesudah tersimpan, panel aslinya ada di atas form, dan kartu rambu di
      bawahnya akan jadi tempat kedua yang mengaku sebagai tempat pengaturan —
      persis kebingungan yang pemindahan ini perbaiki. `?tab=` lama diabaikan,
      jadi tautan basi mendarat di daftar pendaftar, bukan 404.
- [x] **Pagination** 25/halaman (`?page=`), pola `tickets/buyers`. 25, bukan 50:
      tiap baris bisa memuai jadi panel detail.

> **Invarian: `paid` dan `awaiting_verification` dihitung EVENT-WIDE.** Satu
> kalimat di layar berbunyi "N pendaftar · M lunas", dan dua angkanya dulu
> diukur dari populasi berbeda — `total` dari paginator, dua lainnya dihitung di
> klien dari baris yang kebetulan termuat. Tanpa pagination itu tampak benar;
> dengan 25 per halaman, event yang lunas semua berbunyi "120 pendaftar · 25
> lunas" tanpa apa pun di layar yang bilang angka mana yang benar — bentuk bug
> `effective_payment_method`. Dihitung server dari query **yang sama** dengan
> yang melahirkan halamannya, dan `awaiting_verification` lewat scope
> `HasManualPayment` (empat syarat, salah satunya `verified_at IS NULL`), bukan
> dieja ulang. Ujilah dengan **meminta dua halaman** dari daftar yang sama dan
> membandingkan angkanya; assert "paid = 2" pada satu halaman lolos penuh walau
> hitungannya page-wide, karena di halaman yang memuat semuanya kedua cara
> menjawab sama.

> **Invarian: kertas PDF melebar mengikuti jumlah kolom.** `EventExport::paper()`
> menurunkannya dari `count(headings())`, dengan A4 potret sebagai lantai.
> Sebelumnya tidak ada `setPaper` sama sekali: dompdf memakai A4 potret dan
> **memotong kolom kedelapan ke atas** tanpa error — sheet yang tampak lengkap
> padahal separuh jawabannya hilang, dan tidak ada yang memeriksa apa yang tidak
> ada di sana. Flag orientasi per-ekspor akan jadi jawaban kedua atas pertanyaan
> yang sama, benar sekali lalu salah begitu ada yang menambah field pendaftaran;
> ekspor lomba membawa seluruh `registration_form` event, jadi lebarnya memang
> tidak diketahui saat kelasnya ditulis (29 kolom → 1862pt). `table-layout:
> fixed` menyertainya supaya satu catatan medis panjang tidak mengambil
> sepertiga halaman. Ujilah dengan **membandingkan** ekspor lebar vs sempit;
> assert "lebih lebar dari A4" sendirian lolos walau semua ekspor dipaku
> landscape.

## Verifikasi

- `bunx tsc --noEmit` bersih.
- `bunx eslint` identik dengan baseline sebelum perubahan (9 problem, 1 error,
  semuanya pre-existing di file yang tidak disentuh perubahan ini).
- `php artisan test` — 988 passed, 5944 assertions.
- **Belum diuji di browser.**
