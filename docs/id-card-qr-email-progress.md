# Progress: QR verifikasi & kirim ID card via email

Dua penambahan di atas generator ID card yang sudah ada (`docs/id-card-progress.md`):

1. **QR di kartu** — tiap kartu yang dicetak punya nomor dan halaman verifikasi, jadi
   marshal di gerbang bisa memindainya dan tahu kartu itu asli.
2. **Kirim email** — tidak semua penyelenggara mencetak; sebagian cuma mengirimkan
   kartunya. Satu email per **tim** (ke manajernya, berisi kartu seluruh anggota) dan satu
   email per **personel** (wasit/staf, ke email masing-masing).

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Keputusan yang mengikat (sudah diambil, jangan dibuka ulang)

- **Tabel `id_cards`, bukan token bertanda tangan.** Migrasi `create_id_card_templates_table`
  sudah menyatakan ketiadaannya sebagai keputusan sadar *"Adding `id_cards` later would be
  purely additive"*, dan `config/id_card.php` menjelaskan kenapa field `qr` belum ada: kartu
  tak punya row, tak punya nomor, tak ada yang menunjuknya. QR mengubah tepat premis itu.
- **Email ikut `id_card_generator`**, tanpa feature key baru. Saat ini hanya Professional
  yang membawa `id_card_generator`, jadi key kedua tidak memisahkan paket mana pun — ia cuma
  satu baris lagi yang wajib dijaga sejalan di `PlanSeeder` + `FeatureDefinitionSeeder`.
  Beda dari `certificate_email`, yang memang memisahkan sesuatu saat ia ditambahkan.
- **Satu email per tim, bukan per kartu.** `players` dan `team_officials` **tidak punya
  kolom email**; satu-satunya alamat yang dimiliki sebuah tim adalah
  `teams.manager_user_id` → `users.email` (jalan yang sama yang dipakai
  `CertificateService::issueBatch()`). Mengirim per kartu berarti manajer tim beranggota 20
  menerima 20 email berlampiran satu PNG.

---

## Urutan fase (mengikat)

**1 sebelum 2** — field `qr` tak punya apa pun untuk ditunjuk sampai barisnya ada.
**2 sebelum 3** — halaman verifikasi membaca baris yang ditulis renderer.
**1 sebelum 4** — mail melampirkan PNG yang barisnya namai.

| Fase | Isi | Status |
|---|---|---|
| 1 | Tabel `id_cards` + penomoran + PNG tersimpan | ✅ selesai |
| 2 | Field `qr` di template + renderer | ✅ selesai |
| 3 | Halaman verifikasi publik `/verify-card/{nomor}` | ✅ selesai |
| 4 | Kirim email (per tim + per personel) | ✅ selesai |
| 5 | Frontend: editor, halaman cetak, riwayat | ✅ selesai |
| 6 | Sapuan media & prune | ✅ selesai |

**Verifikasi akhir**: `pint --dirty` bersih, suite penuh **1152 lulus / 6985 assertion**
(1 skip pre-existing), `bunx tsc --noEmit` bersih, `bun run build` sukses.

---

## Fase 1 — Tabel `id_cards`

> **Invarian: kartu terbit adalah SNAPSHOT, bukan join.** Alasan yang sama dengan
> `certificates`: pemain bisa dihapus, tim bisa diganti nama, template bisa diedit — kartu
> yang sudah ada di leher orang harus tetap berkata apa yang tercetak padanya.
> `recipient_name`/`role_label`/`team_name` disalin saat terbit. Ujilah dengan **mengubah
> nama pemain sesudah kartu terbit**, lalu assert halaman verifikasi masih mencetak nama
> lama; assert "kartu terbit" sendirian lolos penuh.

- [x] Migrasi `2026_11_08_100000_create_id_cards_table` — `organization_id`, `event_id`
      (cascade), `id_card_template_id` (**`nullOnDelete`**, pola `certificates`: PNG-nya
      sudah terender), `card_number` unique, `recipient_type` (`player|official|personnel`),
      `recipient_id` nullable, snapshot `recipient_name`/`role_label`/`team_name`,
      `recipient_email` nullable, `png_key` nullable, `issued_at`, `sent_at` nullable
- [x] **Unique `(event_id, recipient_type, recipient_id)`, bukan `+ card_number`.** Satu
      orang satu kartu per event — cetak ulang **memperbarui barisnya**, tidak menerbitkan
      nomor kedua. Ini beda dari `certificates`, yang unique-nya memuat `award_title` karena
      satu tim sah menerima "Juara 1" **dan** "Fair Play". Kartu tidak punya axis kedua, dan
      nomor kedua untuk satu orang berarti QR lama di kartu yang sudah dibagikan mati
- [x] `IdCard` model — `$fillable`, cast `issued_at`/`sent_at` datetime, `verifyUrl()`
      (pola `Certificate::verifyUrl()`, membaca `config('id_card.verify_url')`)
- [x] `Organization::idCards()`, `Event::idCards()` hasMany
- [x] `IdCardService::nextNumber()` — `IDC-2026-11-0001`, reset tiap bulan, pola
      `CertificateService::nextNumber()` persis: `lockForUpdate()` atas baris tertinggi
      (Postgres menolak `FOR UPDATE` bersama agregat), unique index sebagai jaring terakhir.
      Dash, bukan slash: nomor ini segmen URL **dan** nama file di dalam zip
- [x] **`issue()` idempoten lewat `firstOrCreate` pada kuncinya**, bukan `create` — cetak
      ulang adalah hal normal yang organizer lakukan (nambah pemain lalu cetak lagi), dan
      `firstOrCreate` di `(event, type, id)`-lah yang menjaga nomor kartu stabil. Nomor baru
      hanya diminta saat barisnya belum ada, jadi batch kedua tidak membakar 400 nomor
- [x] `png_key` — PNG per kartu diparkir di bucket (prefix `id-card-prints/`), **selain**
      zip-nya. Lampiran email dan cetak ulang satu kartu keduanya butuh objek per orang;
      mengiris ulang dari zip berarti mengunduh 400 kartu untuk mengirim 1
- [x] Verifikasi: `pint --dirty`, suite penuh

## Fase 2 — Field `qr` di template

- [x] `config/id_card.php` — tambah `'qr' => 'QR verifikasi'` ke `fields`, dan **ganti
      docblock yang menjelaskan kenapa ia absen**: alasannya ("kartu tak punya row, tak
      punya nomor") sudah tidak benar sejak Fase 1, dan komentar yang menjelaskan keadaan
      lampau lebih buruk daripada tidak ada komentar
- [x] `config/id_card.php` — `'verify_url'` (pola `config('certificate.verify_url')`:
      `FRONTEND_URL` + `/verify-card`) dan `'number_prefix' => env('ID_CARD_PREFIX', 'IDC')`
- [x] **Bentuk field `qr` = kotak, sama dengan `photo`.** `x`/`y` sudut kiri-atas, `w`/`h`
      persen — QR itu bujur sangkar yang digambar ke dalam kotak, bukan teks yang
      menggantung dari titik perataan. Konsekuensinya `IdCardTemplateRules::check()` harus
      memperlakukan `qr` di cabang kotak bersama `photo`, **bukan** jatuh ke cabang teks
      yang akan menagih `size` dan menolak `w`/`h`
- [x] `qr` **tidak menerima `fit`/`radius`**: `contain` pada QR tak punya arti (ia sudah
      bujur sangkar) dan sudut membulat memakan modul sudut yang justru jadi pola pencari.
      Ditolak eksplisit, bukan diabaikan
- [x] `IdCardService::drawQr()` — matriks dari `BaconQrCode\Encoder\Encoder::encode()`,
      digambar ke GD modul demi modul. **Bukan `QrImage::svgDataUri()`**: itu untuk dompdf,
      dan GD tidak bisa menggambar SVG. **Bukan `ImagickImageBackEnd`**: imagick memang ada
      di `api/Dockerfile` (untuk `.ico`) tapi seluruh renderer kartu ini GD, dan PHP lokal
      tanpa imagick akan menjatuhkan setiap test. Matriks murni sudah diverifikasi jalan:
      33×33 modul untuk URL verifikasi sepanjang nomor kita
- [x] **Quiet zone digambar sendiri**: 4 modul putih di sekeliling, bukan `RendererStyle(…, 0)`
      yang dipakai `QrImage`. Alasan yang tertulis di `QrImage` berlaku terbalik di sini —
      sheet PDF punya padding sendiri, latar kartu belum tentu; QR yang mepet grafis latar
      **tidak terpindai**
- [x] **Modul dibulatkan ke integer piksel.** `floor(boxPx / modules)` lalu kotaknya
      dipusatkan dari hasilnya, bukan `boxPx / modules` float: modul selebar 7,4 px
      digambar bergantian 7 dan 8 px dan pola pencarinya menyimpang. Ujilah dengan
      **mendekode PNG hasil render** (atau membandingkan lebar modul pertama dan terakhir),
      bukan assert "ada piksel hitam"
- [x] `render()` menerima `$card` (baris `IdCard`) supaya tahu nomor yang di-encode. Tanda
      tangannya berubah — **template tanpa field `qr` tetap boleh dirender tanpa baris**,
      kalau tidak setiap test render yang ada harus menerbitkan kartu dulu
- [x] Verifikasi: render dengan `qr` **dan** tanpa `qr` dalam satu test, bandingkan

## Fase 3 — Halaman verifikasi publik

- [x] `Api\Public\PublicIdCardController@show` — kembar `PublicCertificateController`, dan
      **sama sempitnya**: nama, peran, tim, event, penyelenggara, nomor, tanggal terbit.
      **Tidak ada `photo_url`** — kartu fisiknya sudah membawa fotonya, dan menerbitkannya
      di endpoint tanpa autentikasi mengubah nomor kartu jadi alat mengunduh foto peserta
- [x] Rute `GET public/id-cards/{number}`, di samping `public/certificates/{number}`
- [x] `web/app/(public)/verify-card/[number]/page.tsx` — fork `verify/[number]/page.tsx`
- [x] Verifikasi: nomor tak terdaftar → 404, nomor terdaftar → payload tanpa foto

## Fase 4 — Kirim email

> **Invarian: `sent_at` ditulis SESUDAH transport, dan dua jalur menulisnya ke baris yang
> berbeda jumlahnya.** Pola `SendCertificateJob` (`sendNow()`, bukan `send()`, supaya
> `sent_at` tidak distempel pada mail yang masih di queue) berlaku apa adanya — bedanya satu
> email tim menstempel **N baris** sekaligus. Kegagalan transport harus meninggalkan
> **seluruh** N bisa dikirim ulang, bukan separuh. Ujilah dengan **membandingkan** kegagalan
> dan keberhasilan pada tim yang sama: assert "sent_at terisi" sendirian lolos walau baris
> yang gagal ikut distempel.

- [x] `SendIdCardBatchJob` — dikelompokkan **per tujuan**, bukan per kartu: manajer tim
      dapat satu mail berisi kartu seluruh anggotanya, personel dapat kartunya sendiri.
      Pengelompokannya di job, bukan di controller: controller tidak boleh jadi pembaca
      kedua atas "email siapa"
- [x] `IdCardsIssuedMail` — lampiran **PNG per orang** dari `png_key`
      (`Attachment::fromStorageDisk`), nama file = `card_number`. Markdown
      `mail/id-cards-issued.blade.php`, pola `certificate-issued`. Satu tombol ke halaman
      verifikasi **hanya saat satu kartu**; untuk tim, daftar nomornya di `mail::table`
      (tombol yang menunjuk satu dari dua puluh lampiran menunjuk yang salah untuk
      sembilan belas orang)
- [x] **Kartu tanpa alamat dilewati, bukan menggagalkan batch** — pola `issueBatch()`
      melewati penerima yang sudah punya penghargaan itu. Yang dilewati **dilaporkan**:
      tim tanpa akun manajer dan personel tanpa email adalah keadaan yang sangat biasa, dan
      "40 kartu dikirim" di atas 55 orang terbaca sebagai bug
- [x] `IdCardController@send` — gate `id_card_generator` **event-keyed** (bukan `orgAllows`:
      yang dikirim kartu event tertentu)
- [x] Verifikasi: `Mail::fake()`, bandingkan jumlah mail (1 tim + 1 personel = 2, **bukan**
      jumlah kartunya)

## Fase 5 — Frontend

- [x] `template-editor.tsx` — `qr` masuk cabang kotak bersama `photo` (geser + resize),
      **tanpa** kontrol `fit`/`radius`. `SAMPLE` tak berlaku; pratinjaunya kotak
      bermotif/ikon QR
- [x] Halaman cetak — tombol "Kirim via email" di samping "Unduh .zip", dengan ringkasan
      berapa tujuan dan berapa yang dilewati **sebelum** diklik
- [x] Riwayat kartu terbit (nomor, nama, `sent_at`) — paginasi, pola
      `CertificateController::index()`
- [x] `web/types/api.ts` + `web/lib/api/id-cards.ts`
- [x] Verifikasi: `bun run build`, `bunx tsc --noEmit`

## Fase 6 — Sapuan & prune

- [x] `MediaCleanupService::eventUrls()` — `id_cards.png_key` ikut disapu saat event
      dihapus (pola `certificates.pdf_key` di baris sebelahnya)
- [x] **`id-cards:prune` TIDAK boleh menyentuh `id-card-prints/`.** Prefix itu permanen —
      barisnya menunjuknya dan QR-nya sudah dibagikan. Alasan yang sudah ditulis di
      `IdCardService::BATCH_PREFIX` dan `CertificateService::BATCH_PREFIX` berlaku penuh:
      pemeriksaan ekstensi sendirian adalah penjaga sedalam satu salah ketik di depan loop
      delete. Ujilah dengan **membandingkan** objek di kedua prefix sesudah sapuan
- [x] Verifikasi: suite penuh

---

## Catatan yang belum diverifikasi

1. **Ukuran QR minimum yang terpindai di kartu CR80.** 33 modul di kotak 18mm = ~0,55mm
   per modul; batas praktis kamera ponsel biasanya ~0,4mm. Belum diuji cetak. Editor
   sebaiknya memperingatkan di bawah ambang tertentu, tapi ambangnya masih tebakan
2. ~~**Panjang URL menaikkan versi QR.**~~ — **terukur**: 33 modul (versi 4) bertahan sampai
   56 karakter pada `ErrorCorrectionLevel::M`, jadi `localhost:3000`, `flo-event.id`, dan
   `app.flo-event.co.id` sama-sama 33 modul. Batas versi berikutnya ada di ~62 karakter, jadi
   domain yang jauh lebih panjang **akan** menaikkannya dan mengecilkan tiap modul di kotak
   yang sama. Yang masih belum diuji: cetak sungguhan pada 33 modul di kotak 17mm
3. **Biaya menyimpan PNG per kartu.** 400 kartu × ~300KB = ~120MB per batch di R2, dan
   objeknya permanen (beda dari zip yang 24 jam). Mungkin perlu kebijakan retensi sendiri,
   tapi membuangnya berarti email ulang harus me-render ulang
4. Round-trip `decimal(6,2)` ↔ float JS untuk `85.6` — warisan dari dokumen induk
