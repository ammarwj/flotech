# Formulir pendaftaran: consent bercatat waktu, panduan field, tampil-bersyarat

Tiga kemampuan yang belum dimiliki `events.registration_form`, diminta oleh
formulir fun run UAD (20 poin data peserta). Ketiganya menempel pada bentuk
field yang sudah ada — tidak ada tabel baru dan tidak ada kolom baru di
`race_entries`/`teams`.

## Prinsip yang dipakai

- **Satu pembaca per aturan.** `RegistrationForm` (server) dan
  `web/lib/registration-form.ts` (klien) adalah satu-satunya yang tahu bentuk
  skema; tidak ada komponen yang mengeja tipe field sendiri.
- **Jam milik server.** Waktu consent distempel di sisi tulis, bukan dikirim
  klien — nilai dari klien bisa dipalsukan, dan "dicatat waktunya" tidak ada
  gunanya kalau yang mencatat adalah yang menyetujui.
- **Field tersembunyi tidak ditagih DAN tidak disimpan.** Dua setengah
  pekerjaan yang masing-masing lolos test satu sisi: menagih field yang tak
  tampak mengunci formulir, menyimpannya meninggalkan NIM pada orang yang
  akhirnya memilih UMUM.

## A. Tipe field `consent` + waktu persetujuan

- [x] `RegistrationForm::TYPES` tambah `consent`. Tanpa prefix/penanda: nilai
      tersimpan **adalah** instant-nya, dan tipe field-lah yang bilang cara
      membacanya — penanda di dalam nilai akan jadi pembaca kedua atas tipe.
- [x] `RegistrationForm::normalizeAnswer()` — satu-satunya penstempel waktu,
      dipakai kedua write path (`TeamRosterService::cleanAnswers()` dan
      `PublicRaceController::cleanAnswers()`).
- [x] Nilai tersimpan = ISO-8601 instant, bukan `"1"`: kolomnya sudah json
      bebas, jadi kolom waktu terpisah akan jadi pembaca kedua.
- [x] `displayValue()` (bukan `consentAt()`) — satu formatter dipakai
      `albumRows()` **dan** `publicAnswers()`, karena keduanya beda soal
      **blank**, bukan soal format. Jam venue lewat `wallet.timezone`; app
      timezone UTC akan mencetak consent 00:30 WIB sebagai hari sebelumnya.
- [x] Uji dengan **membandingkan** jawaban yang dikirim klien berisi timestamp
      karangan vs tidak sama sekali: keduanya harus tersimpan sebagai jam
      server. Assert "tersimpan" saja lolos walau klien yang menentukan jam.

## B. `help_text` + `help_image_url`

- [x] Dua key baru di `cleanFields()` + `validationRules()`, semua tipe.
- [x] `CustomFieldEditor` merender keduanya; gambar lewat `<img>` dengan
      `alt` = `Panduan {label}` — gambar terblokir tetap terbaca milik field
      mana, pola yang sama dengan `alt` di header email.
- [x] Builder: input teks bantuan + `ImageUploadField` per baris. `'' → null`
      di sisi tulis, jadi dua spelling "tanpa panduan" tidak bisa lahir.

## C. `visible_if`

- [x] Bentuk `{field: <key>, values: [<string>, …]}`; null = selalu tampak.
- [x] `RegistrationForm::isVisible(field, answers)` — satu penjawab, dibaca
      `answerErrors`, `assertFormComplete`, dan `cleanAnswers` (yang
      **membuang** jawaban field tak tampak).
- [x] Klien: `isFieldVisible()` di `lib/registration-form.ts`, dibaca
      `CustomFieldEditor` (render, lewat `visibleFields`) dan `missingFor`
      (tagihan). Keempat form pendaftaran ikut tanpa disentuh — keduanya
      memang satu-satunya pintu yang dipakai bersama.
- [x] Jawaban field tersembunyi **tidak** dihapus dari state klien: server
      yang membuangnya saat tulis, dan mengosongkannya di sini akan
      menghilangkan apa yang sudah diketik begitu pemicunya dibolak-balik.
- [x] Picker pemicu di builder cuma menawarkan `select` **di atas** baris itu
      — API menolak yang lain, jadi menawarkannya akan jadi jebakan. Ganti
      pemicu membuang `values` lama (opsi milik field lain tak akan pernah
      cocok dan akan menyembunyikan field selamanya).
- [x] Validasi: `visible_if.field` wajib key yang **sudah ada di daftar yang
      sama dan di atas baris ini** — itu yang menutup siklus tanpa aturan
      kedua, dan sekaligus menjamin pemicunya sudah dirender saat field ini
      diputuskan.
- [x] Uji dengan **membandingkan** satu event, dua pengiriman: pemicu cocok
      (field wajib ditagih, jawabannya tersimpan) vs tidak cocok (tidak
      ditagih, jawabannya dibuang). Assert satu arah lolos walau arah lain
      tidak pernah jalan.

## Event contoh

Organizer `Temporary Organizer` (slug `temporaryorganizer`, owner
`flobamora.tech@gmail.com`) — satu-satunya org akun itu.

- [x] `docs/scripts/seed-uad-funrun.php` — **memanggil endpoint HTTP, bukan
      menulis model**: membuat event membelanjakan kredit paket di dalam
      transaksi, dan skema formulirnya punya validasi sendiri. Insert mentah
      tidak membuktikan keduanya jalan. Idempoten pada slug; periode & jendela
      pendaftaran `updateOrCreate` supaya menjalankan ulang dengan tanggal
      yang diedit benar-benar menggesernya.
- [x] Event `uad-fun-run-2026`, paket **Professional** (Pro tidak memberi
      `online_registration`, jadi fun run berpaket Pro tidak punya form
      publik sama sekali).
- [x] 3 periode (Early Bird / Pre Sale 1 / Pre Sale 2), 4 kategori
      (3K/5K × UAD/Umum) — jarak **dan** status menentukan harga, dan harga
      hidup di kategori, jadi keduanya kategori, bukan field.
- [x] 15 field + 1 dokumen; 4 consent, 3 field ber-`visible_if` ke
      `status_mahasiswa`.
- [x] Diuji end-to-end lewat endpoint publik, tiga kasus pembanding:
      UAD tanpa NIM → 422; UMUM dengan satu consent kosong → 422; UMUM
      lengkap yang **tetap** mengirim NIM → 201 dengan NIM dibuang dan
      keempat consent berisi instant server. Entry ujinya dihapus.
