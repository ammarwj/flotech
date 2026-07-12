# Dompet Organizer & Penarikan Dana

## Kenapa fitur ini ada

Semua pembayaran di flo-event (tiket, biaya pendaftaran tim, langganan) masuk ke **satu akun merchant Midtrans milik platform**. `MIDTRANS_SERVER_KEY` bersifat global — tidak per-organisasi, tidak ada split payment, tidak ada sub-merchant. Artinya pembeli membayar ke platform, **bukan** ke organizer.

Sebelum fitur ini, `platform_fee` sudah dihitung dan disimpan di `ticket_orders` / `teams`, tapi tidak pernah dipakai. Uang organizer mengendap di platform tanpa cara mencairkannya.

Fitur ini menambahkan **dompet per-organisasi** (saldo + ledger) dan **alur pencairan manual** yang diproses super_admin.

> ⚠️ Jangan bingung dengan `teams.status = 'withdrawn'` — itu artinya **tim mundur dari event**, bukan penarikan dana. Domain uang memakai istilah `Withdrawal` / "Penarikan Dana".

---

## Cara kerjanya

### Siklus uang

```
Pembeli bayar (Midtrans → akun platform)
        │
        ▼
  markPaid()  ──►  kredit NETO ke dompet organizer
                   (neto = total − platform_fee)
                   status: PENDING  ← "Saldo Tertahan"
        │
        │  event selesai (status=finished, atau end_date lewat)
        ▼
  wallet:release  ──►  status: AVAILABLE  ← "Saldo Tersedia"
        │
        │  organizer ajukan penarikan
        ▼
  Dana LANGSUNG didebit (amount + biaya admin)
  status penarikan: pending → processing → completed
        │
        ▼
  Super admin transfer MANUAL via m-banking,
  lalu upload bukti & tandai selesai
```

### Kenapa dana ditahan sampai event selesai

Supaya refund tidak perlu "menarik balik" uang yang sudah dicairkan. Kalau kredit masih `pending` saat direfund, kreditnya cukup **dibatalkan** — tanpa debit, tanpa saldo minus. Ini kasus paling umum.

### Saldo boleh minus (disengaja)

Kalau organizer sudah menarik dananya lalu terjadi refund, saldo jadi **minus**. Ini disengaja: platform sudah terlanjur bayar. Konsekuensinya penarikan berikutnya otomatis terkunci (karena `available >= amount + fee` mustahil terpenuhi), dan pendapatan berikutnya akan menutup selisihnya.

Super admin bisa melihat dompet minus lewat `GET /admin/wallets?negative=1`.

### Refund ≠ uang kembali ke pembeli

Refund di sistem ini **membatalkan pesanan + mengoreksi saldo organizer**. Uang **tidak** otomatis kembali ke pembeli — admin harus memproses refund-nya juga di **dashboard Midtrans**. UI admin sudah memperingatkan ini.

---

## Model data

| Tabel | Isi |
|---|---|
| `wallets` | Satu per organisasi. `balance_available`, `balance_pending`, `total_earned`, `total_withdrawn`. Saldo **boleh negatif**. |
| `wallet_transactions` | Ledger immutable. Setiap pergerakan uang = satu baris. |
| `bank_accounts` | Rekening tujuan pencairan (satu `is_primary` per org). |
| `withdrawals` | Permintaan penarikan + **snapshot** rekening tujuan saat itu. |

### Invarian ledger

```
balance_available = Σ ±amount WHERE status = 'available'
balance_pending   = Σ ±amount WHERE status = 'pending'
(status 'cancelled' tidak dihitung)
```

Saldo di tabel `wallets` **didenormalisasi** supaya penarikan bisa cek + lock satu baris. Ledger tetap sumber kebenaran — `wallet:audit` (jadwal harian) membandingkan keduanya dan melaporkan drift.

Tidak ada kolom "sedang diproses". Nilainya **diturunkan** dari withdrawal yang masih terbuka (`Wallet::onHold()`), jadi tidak bisa drift.

### Kategori transaksi

| Kategori | Tipe | Kapan |
|---|---|---|
| `ticket_sale` | credit | Pesanan tiket lunas |
| `registration_fee` | credit | Biaya pendaftaran tim lunas |
| `refund` | debit | Refund atas kredit yang **sudah** available |
| `withdrawal` | debit | Saat penarikan diajukan (dana langsung ditahan) |
| `withdrawal_reversal` | credit | Penarikan ditolak / dibatalkan |
| `adjustment` | credit/debit | Koreksi manual super_admin |

### Idempotensi

Unique index `(source_type, source_id, category)` di `wallet_transactions`. Webhook Midtrans yang dikirim ulang **tidak akan** mengkredit dua kali — dijaga tiga lapis: early-return `markPaid()`, pre-check di `record()`, dan unique index sebagai penjaga terakhir saat race.

---

## Aturan & konfigurasi

Aturan pencairan **diatur super_admin dari UI** (`/admin/settings`) — tidak perlu redeploy untuk mengubah biaya admin atau minimal penarikan.

| Setting | Key | Batas |
|---|---|---|
| Minimal penarikan | `wallet_minimum_withdrawal` | 0 – 100.000.000 |
| Biaya admin per penarikan | `wallet_admin_fee` | 0 – 1.000.000 |
| Masa tahan setelah event selesai (hari) | `wallet_hold_days` | 0 – 90 |

Disimpan di tabel `platform_settings` (key–value), dibaca lewat `PlatformSettings` yang ber-cache. **`config/wallet.php` tetap jadi default** — instalasi baru jalan tanpa satu baris pun di tabel itu; sebuah baris hanya meng-override default-nya.

```env
# Default kalau belum pernah di-set dari admin
WALLET_MIN_WITHDRAWAL=100000
WALLET_ADMIN_FEE=5000
WALLET_HOLD_DAYS=0

# TIDAK bisa diubah dari UI — infrastruktur, bukan kebijakan bisnis
WALLET_TIMEZONE=Asia/Jakarta
```

**Kenapa `WALLET_TIMEZONE` tidak masuk UI:** ini penentu kapan "akhir hari" event, bukan kebijakan bisnis. Salah set bisa mencairkan dana saat event masih berjalan. Biarkan di env.

**Batas nilai divalidasi di backend** (`PlatformSettings::DEFINITIONS`), bukan cuma di UI — biaya admin Rp 5.000.000 tidak bisa tersimpan meski API dipanggil langsung.

**Mengubah setting tidak menulis ulang sejarah.** Setiap baris `withdrawals` menyimpan snapshot `minimum_at_request` dan `admin_fee` saat dibuat, jadi penarikan lama tetap memakai aturan yang berlaku saat itu. Perubahan hanya berlaku untuk penarikan **baru**.

Nilai efektifnya dikirim ke frontend lewat `GET /wallet` → `rules`, jadi UI **tidak pernah** hardcode rupiah.

> ⚠️ `wallet_hold_days` hanya memengaruhi kredit **baru** — `available_at` dihitung saat kredit dibuat, jadi mengubahnya tidak menggeser dana yang sudah tertahan.

**Aturan penarikan:**
- Jumlah ≥ `minimum_withdrawal`
- Saldo tersedia ≥ `amount + admin_fee`
- Rekening bank primary harus ada
- Maksimal **1 penarikan aktif** (`pending`/`processing`) per organisasi

### ⚠️ Timezone — jangan diakali

`events.end_date` bertipe **DATE** dan `config/app.php` timezone-nya **UTC**. Cek naif `end_date <= now()` akan mencairkan dana jam **07:00 WIB di hari terakhir event** — saat event masih berjalan.

Semua logika rilis **wajib** lewat `WalletService::availableAtFor()`.

---

## Keamanan

Middleware `tenant` meloloskan **semua** member organisasi, termasuk role `operator` (petugas scan tiket di gerbang). Tanpa proteksi, operator bisa mengganti rekening tujuan dan menguras dompet.

Semua endpoint uang karena itu memakai middleware **`org.admin`** setelah `tenant` — hanya **pemilik** atau member ber-role **`admin`**.

---

## Endpoint

### Organizer (`tenant` + `org.admin`)

```
GET    /organizations/{org}/wallet                  saldo + rules
GET    /organizations/{org}/wallet/transactions     ledger (paginated)
GET    /organizations/{org}/bank-accounts
POST   /organizations/{org}/bank-accounts           rekening baru jadi primary
PATCH  /organizations/{org}/bank-accounts/{id}
DELETE /organizations/{org}/bank-accounts/{id}
GET    /organizations/{org}/withdrawals
POST   /organizations/{org}/withdrawals             { amount, note? }
DELETE /organizations/{org}/withdrawals/{id}        batal (hanya saat pending)
```

### Super admin (`superadmin`)

```
GET    /admin/withdrawals?status=pending            antrian pencairan
GET    /admin/withdrawals/{id}
PATCH  /admin/withdrawals/{id}/process              pending → processing
PATCH  /admin/withdrawals/{id}/complete             { proof_url* , transfer_reference?, admin_note? }
PATCH  /admin/withdrawals/{id}/reject               { admin_note* } → dana kembali
GET    /admin/payments?status=paid                  semua pembayaran platform
POST   /admin/ticket-orders/{id}/refund             { reason* }
POST   /admin/teams/{id}/refund                     { reason* }
GET    /admin/wallets?negative=1                    dompet semua org
POST   /admin/wallets/{id}/adjust                   { amount, description }
GET    /admin/settings                              aturan pencairan (+ default & batas)
PUT    /admin/settings                              { wallet_minimum_withdrawal?, wallet_admin_fee?, wallet_hold_days? }
```

`complete` **wajib** `proof_url` — pencairan yang ditandai selesai tanpa bukti tidak bisa diaudit.

---

## Halaman UI

| Halaman | Isi |
|---|---|
| `/organizer/wallet` | 4 kartu saldo (Tersedia / Tertahan / Sedang Diproses / Total Ditarik), form rekening, dialog Tarik Dana (ringkasan biaya admin live), riwayat penarikan + bukti transfer, mutasi dompet. Tombol Tarik Dana **disabled dengan alasan eksplisit**. |
| `/admin/withdrawals` | Antrian per status, nomor rekening dengan tombol **copy** (admin mengetik ulang ke m-banking), aksi Proses / Tandai Selesai (upload bukti) / Tolak. |
| `/admin/payments` | Semua pembayaran lunas + aksi Refund (alasan wajib), dengan peringatan bahwa uang tidak otomatis kembali ke pembeli. |
| `/admin/settings` | Atur minimal penarikan, biaya admin, masa tahan. Menampilkan nilai bawaan dan menandai mana yang sudah diubah. |

---

## Perintah artisan

| Perintah | Fungsi |
|---|---|
| `wallet:release` | Cairkan saldo tertahan untuk event yang sudah selesai. **Terjadwal per jam.** Idempoten. `--event={id}` untuk satu event. |
| `wallet:audit` | Bandingkan saldo vs ledger, laporkan drift. **Terjadwal harian 01:00.** Read-only. |
| `wallet:backfill` | **Wajib dijalankan sekali saat deploy.** Buat entri dompet untuk pesanan/pendaftaran lunas yang sudah ada. Idempoten. |

> **Tanpa `wallet:backfill`**, semua organizer lama membuka halaman Dompet dan melihat **Rp 0** padahal platform memegang uang mereka.
>
> **Tanpa scheduler jalan**, saldo tidak pernah pindah dari Tertahan ke Tersedia dan tombol Tarik Dana tidak pernah aktif.

---

# Cara Mengetes

## 1. Automated test (paling cepat, tidak butuh Docker)

```bash
cd api

# Semua test (100 test)
./vendor/bin/phpunit

# Hanya yang terkait dompet
./vendor/bin/phpunit --filter 'Wallet|Withdrawal|Refund'
```

Test suite jalan di sqlite `:memory:` dan `MIDTRANS_SERVER_KEY` dikosongkan di `tests/bootstrap.php`, jadi gateway masuk **mock mode** dan pembayaran langsung lunas — pemasukan gampang disimulasikan tanpa mock manual.

| File test | Yang dibuktikan |
|---|---|
| `WalletTest` | Kredit neto (2×50rb, fee 5% → 95rb pending); tiket gratis → **nol** baris ledger; plan tanpa fee → kredit penuh; biaya pendaftaran; **double-credit dicegah** (webhook Midtrans dikirim 2× dengan signature sha512 asli → tetap 1 baris). |
| `WalletReleaseTest` | Rilis setelah event selesai; idempoten; event masih jalan → tetap tertahan; event `cancelled` → **tidak pernah** dirilis; `PATCH events/{id}` ke `finished` → rilis instan; **batas WIB**: 10:00 UTC (17:00 WIB) belum rilis, 17:00 UTC (00:00 WIB besok) baru rilis. |
| `WithdrawalTest` | Tanpa rekening → 422; di bawah minimum → 422; saldo cukup untuk `amount` tapi tidak untuk `amount+fee` → 422; dana pending tidak bisa ditarik; happy path (dana langsung ditahan); **1 request aktif**; batal → dana kembali; **member `operator` → 403**; org lain → 403. |
| `AdminWithdrawalTest` | Non-superadmin → 403; `complete` tanpa `proof_url` → 422; `complete` **tidak** menulis ledger baru (debit sudah terjadi saat request); `reject` → dana kembali + baris `withdrawal_reversal`; reject yang sudah `completed` → 409. |
| `RefundTest` | Refund saat pending → kredit `cancelled`, **tanpa** debit, **tanpa** minus; refund setelah cair → debit; **refund setelah ditarik → saldo minus & penarikan terkunci**; kuota tiket dilepas; refund 2× → 422; tiket sudah check-in → tidak bisa direfund. |
| `WalletLedgerTest` | **Invarian**: setelah rangkaian campuran (kredit → rilis → WD ditolak → WD selesai → refund → adjustment), saldo tersimpan == Σ ledger, dan `wallet:audit` sukses. |
| `PlatformSettingTest` | Setting jatuh ke default `config/wallet.php`; non-superadmin → 403; mengubah minimal/biaya **langsung berlaku** untuk penarikan baru; **penarikan lama tetap memakai biaya lamanya** (snapshot); nilai absurd (biaya Rp 5jt, minimal negatif, tahan 365 hari) → 422. |

## 2. Tes manual via UI (end-to-end)

**Siapkan:**
```bash
# Backend (WAJIB pakai kedua file compose)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose exec api php artisan migrate

# Frontend
cd web && bun run dev
```

Tanpa `MIDTRANS_SERVER_KEY`, gateway jalan di **mock mode** → setiap pembelian langsung lunas. Sempurna untuk menguji alur dompet.

**Skenario:**

1. **Pemasukan.** Buat event dengan kategori tiket berbayar (mis. Rp 50.000), buka halaman publik event, beli 2 tiket.
   → Buka `/organizer/wallet`: **Saldo Tertahan** bertambah neto (gross − biaya platform). Mutasi menampilkan "Penjualan Tiket / Tertahan".

2. **Pencairan saldo.** Ubah status event jadi **Selesai** (`/organizer/events/{id}/edit`).
   → Refresh `/organizer/wallet`: dana pindah ke **Saldo Tersedia**.
   → Alternatif: `docker compose exec api php artisan wallet:release`

3. **Ajukan penarikan.** Tambah rekening bank, klik **Tarik Dana**.
   → Dialog menampilkan ringkasan live: "Kamu terima Rp 100.000 · Biaya admin Rp 5.000 · Total dipotong Rp 105.000 · Sisa saldo …"
   → Setelah submit: saldo **langsung** berkurang, kartu **Sedang Diproses** terisi, tombol Tarik Dana terkunci ("Masih ada penarikan yang sedang diproses").

4. **Proses sebagai admin.** Login sebagai `super_admin` → `/admin/withdrawals`.
   → Salin nomor rekening (tombol copy), klik **Proses**, lalu **Tandai Selesai** + upload bukti transfer.
   → Organizer melihat status **Selesai** + link bukti.
   → Coba juga **Tolak** (alasan wajib) → dana kembali ke Saldo Tersedia.

5. **Refund & saldo minus.** `/admin/payments` → Refund pesanan yang dananya sudah dicairkan & ditarik.
   → Saldo organizer jadi **minus** (merah, ada banner), tombol Tarik Dana terkunci.

6. **Cek integritas.**
   ```bash
   docker compose exec api php artisan wallet:audit
   # → "Semua dompet sinkron dengan ledger."
   ```

## 3. Tes cepat lewat skrip (tanpa klik UI)

Untuk membuktikan seluruh alur uang dalam sekali jalan terhadap Postgres asli, jalankan skrip yang:
menjual tiket → menyelesaikan event → mengajukan WD → memblokir WD kedua → menyelesaikan WD → refund → memverifikasi saldo minus mengunci penarikan.

Output yang diharapkan:

```
1. Setelah jual tiket → pending=190000.00 available=0.00
2. Event selesai      → pending=0.00      available=190000.00
3. Ajukan WD 150rb    → available=35000.00 on_hold=155000
4. WD kedua ditolak   → "Masih ada permintaan penarikan yang sedang diproses."
5. Admin selesaikan   → available=35000.00 total_withdrawn=150000.00 on_hold=0
6. Refund             → available=-155000.00 (minus = disengaja)
7. WD terkunci        → "Saldo tersedia tidak mencukupi (sudah termasuk biaya admin Rp 5.000)."
8. Kuota tiket dilepas: sold=0, status order=refunded
```

## Checklist sebelum deploy produksi

- [ ] `php artisan migrate`
- [ ] `php artisan wallet:backfill` ← **jangan dilewat**
- [ ] Scheduler jalan (`php artisan schedule:work` / cron), supaya `wallet:release` jalan per jam
- [ ] Atur minimal penarikan & biaya admin di **`/admin/settings`** (atau biarkan pakai default dari env)
- [ ] Pertimbangkan masa tahan > 0 hari sebagai bantalan kalau event batal setelah dana dicairkan

---

## Yang sengaja belum dikerjakan

1. **Refund tidak mengembalikan uang ke pembeli.** Harus diproses juga di dashboard Midtrans.
2. **Webhook Midtrans status `refund`/`partial_refund`** masih jatuh ke `default => null` di `MidtransWebhookController`. Refund yang dipicu dari dashboard Midtrans belum tersinkron ke dompet.
3. **Refund parsial** belum ada — refund bersifat semua-atau-tidak per pesanan.
4. **Org tanpa plan** → `platformFee()` = 0, platform bayar 100% tanpa pendapatan. Pertimbangkan floor fee.
5. **Presisi uang**: Midtrans di-charge `(int) round($total)` sementara DB `decimal(12,2)`. Harga 50.000,50 → pembeli bayar 50.001 tapi dikredit 50.000,50. Sebaiknya harga tiket & `registration_fee` divalidasi sebagai **integer** (rupiah tidak punya sen).
6. **Hapus organisasi**: semua FK `cascadeOnDelete` dan belum ada endpoint delete org. Jangan tambahkan tanpa guard "saldo ≠ 0 atau ada penarikan aktif".
