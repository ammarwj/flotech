# e2e — Playwright

End-to-end tests for the PRD user flows (§5), driving the real stack: the Next.js
app on `:3000` against the dockerized Laravel API on `:8000`.

## Menjalankan

Kedua server harus sudah hidup (suite ini sengaja tidak menyalakannya — keduanya
milik kamu, dan Playwright yang ikut menyalakan hanya akan bentrok dengan
instance yang sedang kamu pakai):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d   # API
cd web && bun run dev                                                  # Web
```

Lalu:

```bash
cd e2e
bun install
bunx playwright install chromium   # sekali saja
bun run test                       # semua flow
bun run test:ui                    # mode UI, enak untuk debugging
bun run report                     # buka laporan HTML terakhir
bunx playwright test specs/wallet-payout.spec.ts   # satu file saja
```

`bun run test`, **bukan** `bun test`: yang terakhir menjalankan test runner
bawaan Bun, yang memuat spec-nya sendiri lalu gagal dengan "Playwright Test did
not expect test.describe() to be called here" — bunyinya seperti spec rusak,
padahal cuma salah runner.

`globalSetup` menolak jalan (dengan pesan yang memberi tahu perintahnya) kalau
API/web mati atau seeder belum pernah dijalankan.

## Cakupan

| Spec | PRD |
|---|---|
| `auth-onboarding.spec.ts` | §5.1 daftar akun → onboarding → buat & publish event, plus jalan masuk lupa password |
| `team-registration.spec.ts` | §5.2 peserta daftar tim → organizer approve/reject → tim muncul di Area Peserta |
| `schedule-results.spec.ts` | §5.3 generate jadwal → input skor → konfirmasi → klasemen ikut berubah |
| `wallet-payout.spec.ts` | §5.7 organizer tarik dana & §5.8 super admin proses/tolak pencairan |
| `manual-team.spec.ts` | tim yang didaftarkan organizer sendiri (di luar aplikasi) |
| `public-header.spec.ts` | header publik yang sadar status login |
| `platform-settings.spec.ts` | saklar payment gateway di `/admin/settings` (lihat "Transfer manual" di bawah) |
| `landing-content.spec.ts` | FAQ & testimoni landing yang diedit super admin — konten, bukan kode |
| `subscription-manual.spec.ts` | rekening penerima pembayaran paket + antrean `/admin/plan-orders` (aman paralel) |
| `subscription-manual-flow.spec.ts` | beli paket lewat transfer manual, ujung ke ujung — **`@gateway-off`, tidak ikut run default** |

## Cara kerjanya

**Data dibuat sendiri, bukan dari seeder.** Tiap test mendaftarkan user baru
(email acak) lewat API dan membangun org/event/tim-nya sendiri, jadi suite ini
aman dijalankan kapan saja di DB dev tanpa merusak data yang ada dan tanpa
saling mengotori antar-test. Satu-satunya akun seeder yang dipakai adalah
`admin@flo-event.id` (super admin) — perannya tidak bisa dibuat lewat API.
Pengecualiannya konten landing (FAQ/testimoni): itu global dan terlihat siapa
pun yang membuka landing dev, jadi spec yang membuatnya wajib menyapunya lagi.

**Org fixture selalu punya paket.** Tidak ada tier gratis: org tanpa paket tidak
punya entitlement sama sekali (`PlanGate::withinLimit()` menolaknya lebih dulu)
dan bahkan tidak bisa membuat event. `createOrg()` karena itu mengirim `plan_id`
— defaultnya `pro`, override per spec kalau yang diuji justru batas paketnya.
⚠️ **Fixture `grantCredit` belum jalan.** Paket kini dibeli per event: `createEvent`
menghabiskan satu plan order yang sudah lunas, dan tidak ada jalan mengaturnya lewat
API selama `MIDTRANS_SERVER_KEY` terisi di `api/.env` — checkout mengembalikan
redirect Snap sungguhan dan meninggalkan ordernya `past_due`. Lihat komentar di
`fixtures/api.ts` untuk dua jalan keluarnya. Sampai itu diputuskan, spec yang
membuat event akan gagal di setup.

**API dipakai untuk menyiapkan, browser untuk menguji.** Yang dibuktikan sebuah
test hidup di UI; yang sekadar perlu ada sebelumnya dibangun lewat `fixtures/api.ts`,
karena satu request jauh lebih murah daripada satu alur klik.

**Login lewat cookie, bukan form.** Access token disimpan di memori dan sesi
bertahan lewat cookie refresh HttpOnly. `signIn()` menembak endpoint login API
melalui `page.request` (yang berbagi cookie jar dengan browser), lalu aplikasi
boot dalam keadaan sudah masuk. Hanya `auth-onboarding.spec.ts` yang benar-benar
mengisi form login. Cookie mengabaikan port, itulah sebabnya cookie dari `:8000`
terkirim ke aplikasi di `:3000`.

**Dua orang, dua browser.** Alur pencairan melibatkan organizer *dan* super
admin. Mengganti sesi di dalam satu context tidak memodelkan itu (shell aplikasi
tetap memakai identitas saat ia boot), jadi super admin memakai fixture
`adminPage` — browser context terpisah.

**Uang.** Dompet tidak bisa diisi lewat pembayaran sungguhan (butuh webhook
Midtrans), jadi saldo dipasang lewat penyesuaian ledger milik super admin
(`POST /admin/wallets/{id}/adjust`) — yang diuji flow-nya memang pencairan, bukan
pemasukannya.

**Transfer manual: diisolasi lewat penjadwalan, bukan lewat data.**
`payment_gateway_enabled` itu setting **global platform** — tidak ada override
per organisasi. Mematikannya dari sebuah spec akan mengalihkan jalur pembayaran
semua spec lain yang sedang jalan berbarengan di DB dev yang sama; ini
satu-satunya state yang tidak bisa diisolasi per test. Jadi pembagiannya:

- `api/tests/Feature/ManualPaymentTest.php` & `ManualSubscriptionTest.php` —
  aturan uangnya (order manual tidak pernah mengkredit dompet, `platform_fee` =
  0, langganan manual tidak pernah lewat cabang `mock`, siklus tolak/unggah
  ulang/acc, siapa yang boleh meng-acc, kedua command `expire-manual`). Di
  sqlite in-memory flag-nya per-test, jadi gateway boleh dimatikan sesuka hati.
- `platform-settings.spec.ts`, `manual-payment.spec.ts`,
  `subscription-manual.spec.ts` — bagian yang hanya terlihat di browser dan
  tetap benar saat gateway **hidup**: peringatannya muncul sebelum admin
  menyimpan, dan kedua antrean verifikasi bisa dibuka. Ketiganya **tidak pernah
  menyimpan** saklar itu.
- `subscription-manual-flow.spec.ts` — alur penuhnya (onboarding → transfer →
  tolak → unggah ulang → acc → paket aktif). Ini **memang** mematikan saklarnya,
  karena fiturnya cuma ada saat saklar itu mati; berhenti di depan pintu berarti
  alur ini tidak pernah terbukti jalan di browser sama sekali. Isolasinya lewat
  jadwal: ditandai `@gateway-off`, dibuang dari run lain oleh `grepInvert` di
  `playwright.config.ts`, dan dijalankan sendirian:

  ```bash
  bun run test:gateway-off     # GATEWAY_OFF=1 + --grep @gateway-off + --workers=1
  ```

  `afterAll`-nya menyalakan saklar itu lagi **walaupun test-nya gagal** — kalau
  tidak, run yang crash meninggalkan DB dev dalam mode transfer manual, yang
  persis terlihat seperti bug aplikasi. Jangan pernah menaruh test di file itu
  kalau ia tidak butuh saklarnya; tempatnya `subscription-manual.spec.ts`, yang
  ikut run default tanpa biaya.

Kalau langkah unggah bukti tiba-tiba gagal di beberapa spec sekaligus
(`subscription-manual-flow`, `wallet-payout`, foto pemain di `manual-team`),
curigai R2 lebih dulu, bukan aplikasinya — ketiganya memakai jalur yang sama:
minta tanda tangan ke API kita, PUT byte-nya ke R2, kirim `file_url` balik.
Cara memisahkan CORS dari bucket yang salah adalah menembak PUT presigned itu
dari server:

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@flo-event.id","password":"password"}' | jq -r .data.access_token)
URL=$(curl -s -X POST http://localhost:8000/api/v1/uploads/sign -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"file_name":"probe.png","content_type":"image/png","folder":"payment-proofs"}' | jq -r .data.upload_url)
curl -o /dev/null -w '%{http_code}\n' -X PUT --data-binary @fixtures/transfer-proof.png \
  -H 'Content-Type: image/png' "$URL"
```

`404` di sini berarti bucketnya (`R2_BUCKET` di `api/.env`) yang salah/tidak ada
— bukan CORS, dan bukan spec-nya.

## Catatan lingkungan

- Dev server API (`artisan serve`) melayani satu request pada satu waktu kecuali
  di-fork; `PHP_CLI_SERVER_WORKERS=8` di `docker-compose.dev.yml` yang membuat
  suite paralel ini mungkin. Worker Playwright dibatasi 4 agar sepadan.
- Mail tidak lagi memblokir. `api/.env` sekarang `MAIL_MAILER=smtp` (mailtrap),
  tapi semua notifikasi `ShouldQueue` dan container `worker` (horizon) yang
  mengirimkannya — request register-nya sendiri tidak menunggu SMTP. Kalau worker
  mati, mail-nya cuma menumpuk di antrean, bukan membekukan dev server.
- Test yang menyelesaikan pencairan mengunggah gambar bukti transfer
  (`fixtures/transfer-proof.png`, 16×16) ke R2 — ini menulis objek kecil ke
  bucket `payout-proofs/` sungguhan.
- `getByText("X")` itu **substring & case-insensitive**. `getByText("Nonaktif")`
  pernah lolos hanya karena mencocoki kalimat "Yang nonaktif tidak ikut tampil"
  di deskripsi halaman, bukan badge-nya — assertion-nya hijau tapi tidak menguji
  apa pun. Untuk label pendek pakai `{ exact: true }`, dan sesekali rusak
  sengaja kodenya untuk memastikan test-nya memang bisa merah.
