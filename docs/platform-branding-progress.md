# Progress: CMS branding platform (logo & favicon)

Super admin bisa mengunggah logo dan favicon platform di `/admin/site-settings`; keduanya
dipakai di seluruh aplikasi, halaman publik, dan header email.

- **Rencana lengkap**: `/Users/ammar/.claude/plans/di-projek-ini-menggunakan-bright-zephyr.md`

> ⚠️ **Wajib rebuild image**: `docker compose build api` (dan `worker`/`scheduler` kalau
> image-nya terpisah). `php artisan migrate` saja tidak cukup — favicon butuh ekstensi
> imagick yang baru ditambahkan ke `api/Dockerfile`.

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Selesai

- [x] Migration `2026_09_14_130000` — `logo_url`/`favicon_url` di `site_settings` + `$fillable`
- [x] `api/Dockerfile` — ekstensi **imagick**, satu-satunya alasannya: Intervention hanya
      punya `IcoEncoder` untuk driver Imagick, GD tidak bisa. Semua upload lain tetap GD+WebP
- [x] `UploadController::favicon()` — `cover(64,64)` + `IcoEncoder`, route
      `POST admin/uploads/favicon` di grup **`superadmin`** (beda dari `uploads/*` lain yang
      sengaja publik). Sekalian: duplikasi simpan-ke-disk di `image()`/`document()` ditarik
      ke helper `store()`
- [x] Validasi URL + `SiteSettingResource` & `PublicSiteSettingResource`
- [x] `/admin/site-settings` dipecah jadi **4 tab** (`PillTabs` + tab di URL, pola sama
      dengan `/organizer/wallet`): Identitas · Kontak · Media Sosial · Rekening. Judul halaman
      & menu sidebar → **"Pengaturan Situs"** (ikon `Palette`, bukan `Settings2` yang sudah
      dipakai "Opsi Konfigurasi" tepat di atasnya). **Simpan per tab**: tiap card punya
      tombolnya sendiri di footer, dan payload-nya hanya berisi field tab itu.
      Aman karena `fill($request->validated())` cuma menyentuh key yang dikirim — field yang
      absen mempertahankan nilainya, bukan ter-null. Dikunci test
      `test_saving_one_group_of_fields_leaves_the_others_untouched`, yang meng-assert field
      yang **tidak** dikirim: assert field yang disimpan berubah akan lolos walau sisanya
      terhapus. `SiteSettingsInput` jadi `Partial<SiteSettings>` supaya payload sebagian valid
- [x] `ImageUploadField` prop `upload` opsional — favicon melewati `compressToWebp` karena
      `.ico` tidak bisa di-encode dari blob WebP
- [x] `components/shared/logo.tsx` jadi **satu sumber**. Markupnya ternyata disalin di
      **empat** tempat, bukan tiga: `landing/nav.tsx`, `landing/footer.tsx`, dan
      `app/(auth)/layout.tsx` — yang terakhir baru ketahuan saat user melaporkan halaman
      login masih memakai logo bawaan. Kalau menambah surface logo baru, grep
      `logo-mark` dulu.
- [x] **Tidak ada flash logo bawaan saat refresh.** `Logo` client-side, jadi render
      pertama belum punya data dan mark bawaan sempat terlukis sebelum ditukar — paling
      terlihat di halaman login, yang logonya satu-satunya isi layar. Root layout sudah
      mem-fetch `/site-settings` untuk favicon, jadi hasilnya dioper ke `Providers` dan
      di-`setQueryData` **di dalam inisialisasi `useState`**, bukan di effect: effect jalan
      setelah paint pertama — frame yang justru jadi masalahnya. Next men-dedupe fetch-nya
      dengan milik `generateMetadata`, jadi tanpa request tambahan.
      `SITE_SETTINGS_KEY` diekspor supaya seed, hook, dan invalidate di halaman admin tidak
      mungkin menyimpang.
- [x] **Cache logo, dua lapis.**
      (a) `lib/hooks/use-site-settings.ts` — satu hook dipakai `Logo` **dan** footer.
      react-query menyelesaikan opsi per *observer*, bukan per key: footer memakai default
      60 detik sementara logo meminta 5 menit berarti yang mana pun mounted duluan tetap
      refetch sesuai jadwalnya sendiri, dan `staleTime` di satu pemanggil jadi sia-sia.
      Hook bersama yang membuat jendela cache-nya fakta, bukan harapan. `staleTime` 5 menit,
      `gcTime` 30 menit; halaman admin tetap meng-invalidate key ini saat simpan.
      (b) `R2StorageService::put()` mengirim `Cache-Control: public, max-age=31536000,
      immutable` — **opt-in lewat `immutable: true`**, bukan default. Key upload berisi UUID
      jadi logo yang diganti = URL baru, tidak ada yang perlu divalidasi ulang. Sertifikat
      **sengaja tidak** memakainya: key-nya tetap (`certificates/{id}.pdf`) dan bisa ditulis
      ulang saat diterbitkan ulang — `immutable` di sana akan menyajikan PDF basi tanpa cara
      browser menyadarinya. Terverifikasi lewat R2 sungguhan (`curl -I` → header terkirim).
- [x] `generateMetadata` favicon di root layout (revalidate 300 + try/catch)
- [x] Header email `<img>` + `alt` wordmark, fallback teks saat belum ada logo
- [x] Test: `SiteSettingTest` (+4 test branding) & `PlatformBrandingTest` (4 test).
      **Suite penuh 565 passed**, `tsc`/`eslint`/`bun run build` bersih

---

## Temuan penting (jangan diulang)

- **`app/favicon.ico` menimpa `metadata.icons` sepenuhnya.** Ini ditemukan saat verifikasi
  browser, bukan saat menulis rencana — rencananya justru menyebut berkas itu "fallback yang
  tetap ada". Selama ia di `app/`, favicon terunggah **tidak akan pernah** tampil, dan
  gejalanya diam: metadata benar, tag yang terender tetap ikon lama. Dipindah ke
  `public/favicon.ico`, dan tiap cabang `generateMetadata` sekarang mengembalikan `icons`
  eksplisit.
- **`.ico` mustahil dengan GD.** Dicek sebelum menulis kode, bukan diasumsikan:
  `Drivers/Gd/Encoders/` tidak punya `IcoEncoder`, hanya `Drivers/Imagick/`.
- **Test ICO memeriksa signature `00 00 01 00`**, bukan nama berkas — assert nama berakhiran
  `.ico` akan lolos walau encoder-nya tidak pernah jalan. Di-skip (bukan dihapus) saat
  imagick absen, dengan pesan yang menyuruh rebuild.

## `docker-compose.override.yml` (dev, tidak di-commit)

Container `api`/`scheduler` menolak start di macOS: keduanya bind-mount `/opt/flo-event/*`
untuk sertifikat custom domain, dan Docker Desktop tidak membagikan `/opt`. Override lokal
mengarahkannya ke `./.docker/*` di dalam repo, plus mount `./api:/var/www/html` — image
di-build dengan `composer --no-dev`, jadi **phpunit hanya ada di `vendor/` host**; tanpa
mount itu container tidak punya test runner sama sekali (`artisan test` → "Command not
defined"). `APP_ENV=local` menimpa nilai produksi di file dasar.

Override ini juga **mem-publish `127.0.0.1:8000:8000`**. File dasar sengaja tidak: di
produksi nginx yang menghadap container ini dari dalam jaringan Docker. Tapi di dev, Next.js
jalan di host dan memanggil `NEXT_PUBLIC_API_URL=http://localhost:8000` — tanpa port
ter-publish, semua request dari browser (termasuk login) gagal tanpa bisa tersambung.

> **Wajib ada di `.gitignore`.** `deploy.sh` menjalankan `docker compose up -d --build`,
> yang membaca file override secara otomatis — override dev yang ikut ter-commit akan
> menimpa image produksi dengan source tree dan mengarahkan path sertifikat ke direktori
> yang tidak ada di server.

## Belum dikerjakan
- [ ] Verifikasi manual: unggah logo & favicon sungguhan lewat UI, cek tab browser (hard
      reload — favicon di-cache agresif), lalu kosongkan keduanya dan pastikan semua surface
      kembali ke mark bawaan.

## Halaman 404

`web/app/not-found.tsx` (sebelumnya default Next). Dipakai untuk rute tak dikenal **dan**
`notFound()` — yang dipanggil profil organizer publik saat slug tidak ada, jalur paling
mungkin seorang pengunjung mendarat di sini. Karena itu tombolnya "Jelajahi event" dan "Ke
beranda", bukan tombol "kembali" yang justru memulangkan ke tautan rusaknya.
