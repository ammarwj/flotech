# Panduan Deploy VPS — flo-event

Panduan langkah demi langkah menaruh flo-event di satu VPS memakai Docker Compose.
Seluruh runtime (PHP 8.4, Bun/Node, Postgres 17, Redis 8, Nginx, Certbot) ada di
dalam image — VPS **hanya** butuh Docker + plugin Compose. Tidak perlu memasang
PHP, Node, atau Postgres di host.

Stack yang dijalankan `docker-compose.yml`:

| Service     | Peran                                   | Port |
|-------------|-----------------------------------------|------|
| `nginx`     | reverse proxy + TLS                     | 80, 443 |
| `web`       | Next.js (frontend)                      | 3000 (internal) |
| `api`       | Laravel (`artisan serve`)               | 8000 (internal) |
| `worker`    | Horizon (queue)                         | — |
| `scheduler` | `schedule:work` (cron Laravel)          | — |
| `db`        | PostgreSQL 17                           | — |
| `redis`     | Redis 8                                 | — |
| `certbot`   | perpanjang cert Let's Encrypt tiap 12 jam | — |

---

## 1. Prasyarat

**VPS**: Ubuntu 22.04/24.04 (atau Debian setara), ≥ 2 vCPU, ≥ 2 GB RAM, ≥ 20 GB
disk. Root/sudo.

**Domain & DNS** — buat A record ke IP VPS untuk **kedua** nama (keduanya wajib
resolve sebelum menjalankan `init-letsencrypt.sh`, karena satu sertifikat mencakup
keduanya). Tidak ada `www.` — ini sudah sub-subdomain di bawah `flotech.id`:

```
flo-event.flotech.id    A   <IP_VPS>
api-flo-event.flotech.id    A   <IP_VPS>
```

`cdn.flo-event.id` (aset publik R2) di-CNAME ke Cloudflare R2, **bukan** ke VPS.

**Akun pihak ketiga yang perlu disiapkan lebih dulu:**
- Cloudflare R2 — bucket + access key (penyimpanan file/gambar).
- Midtrans — server key & client key (pembayaran).
- Resend — API key (email).
- Sentry — DSN (opsional, monitoring error).

---

## 2. Pasang Docker di VPS

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER      # agar bisa jalan tanpa sudo
newgrp docker                      # aktifkan grup di sesi ini
docker compose version             # pastikan plugin Compose ada
```

Buka firewall port 80 & 443:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80,443/tcp
sudo ufw enable
```

---

## 3. Ambil kode & buat file environment

```bash
git clone <repo-url> flo-event
cd flo-event

cp .env.example .env                 # variabel Compose (domain, Postgres)
cp api/.env.example api/.env         # secret aplikasi Laravel
cp web/.env.example web/.env.production   # NEXT_PUBLIC_* untuk build frontend
```

Ada **tiga** file env dengan peran berbeda — jangan tertukar:

- **`.env` (root)** hanya untuk *interpolasi Compose* (`${API_DOMAIN}`,
  `${POSTGRES_*}`). Isinya **tidak** diinjeksikan ke container `api` —
  menaruh `APP_KEY`/`JWT_*` di sini tidak berpengaruh.
- **`api/.env`** dibaca container `api`/`worker`/`scheduler` via `env_file`.
  Di sinilah semua secret Laravel yang sebenarnya.
- **`web/.env.production`** dibaca Next.js **saat build** (nilai `NEXT_PUBLIC_*`
  di-inline ke bundle). Mengubahnya butuh rebuild image `web`.

### 3a. Isi `.env` (root)

```dotenv
APP_DOMAIN=flo-event.flotech.id
API_DOMAIN=api-flo-event.flotech.id
LETSENCRYPT_EMAIL=admin@flotech.id

POSTGRES_DB=flo_event
POSTGRES_USER=flo_user
POSTGRES_PASSWORD=<password-postgres-kuat>
```

### 3b. Isi `api/.env`

Wajib benar, kalau tidak container gagal boot atau uang/token bermasalah:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # diisi di langkah 4
APP_URL=https://api-flo-event.flotech.id
FRONTEND_URL=https://flo-event.flotech.id

# Harus SAMA PERSIS dengan POSTGRES_* di .env root
DB_HOST=db
DB_DATABASE=flo_event
DB_USERNAME=flo_user
DB_PASSWORD=<password-postgres-kuat>

# Redis: image ini TIDAK punya extension phpredis — pakai predis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=null

# R2, Midtrans, Resend, dsb.
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=flo-event-storage
R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
R2_PUBLIC_URL=https://cdn.flo-event.id

MIDTRANS_SERVER_KEY=...
MIDTRANS_CLIENT_KEY=...
MIDTRANS_IS_PRODUCTION=true

MAIL_MAILER=resend
RESEND_API_KEY=...

TELESCOPE_ENABLED=false     # jangan pernah true di produksi
```

> **Gotcha kritis:**
> - **`DB_USERNAME`/`DB_PASSWORD` (api/.env) wajib sama dengan
>   `POSTGRES_USER`/`POSTGRES_PASSWORD` (.env root).** Root `.env` yang membuat
>   database; `api/.env` yang menyambunginya. Beda sedikit → api tak bisa connect.
> - **`REDIS_CLIENT=predis`.** Image `api` hanya membawa `predis` (PHP murni).
>   `api/.env.example` bawaannya `phpredis`, dan extension itu tidak dikompilasi
>   di Dockerfile — biarkan `phpredis` maka setiap request Redis error.
> - **`REDIS_PASSWORD=null`.** Service `redis` di compose tidak memasang password,
>   jadi jangan set nilai lain (yang di `.env` root tidak dipakai redis).
> - **`MAIL_MAILER=resend`** (bawaan example `log` — email cuma masuk file log).

### 3c. Isi `web/.env.production`

```dotenv
NEXT_PUBLIC_API_URL=https://api-flo-event.flotech.id/api/v1
NEXT_PUBLIC_APP_URL=https://flo-event.flotech.id
NEXT_PUBLIC_SENTRY_DSN=      # opsional
```

---

## 4. Generate APP_KEY & kunci JWT (di host, sebelum build)

**Generate `APP_KEY` dengan `openssl`, bukan `php artisan key:generate`.** Di
produksi tidak ada bind-mount `./api`, jadi `key:generate` akan menulis `.env` di
*dalam* container dan hilang saat container di-recreate. Tempel manual:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
# salin baris di atas ke api/.env (ganti baris APP_KEY yang kosong)
```

**Buat pasangan kunci RS256 SEBELUM build.** Image mem-bake kunci lewat `COPY . .`,
dan `.gitignore` menjaga `*.pem` keluar dari repo — checkout baru tidak punya kunci.
Kalau build duluan, `api`/`worker`/`scheduler` naik tapi tak bisa menandatangani
token. `init-letsencrypt.sh` juga ikut mem-build, jadi buat kunci di sini:

```bash
openssl genrsa -out api/storage/jwt/jwt-private.pem 2048
openssl rsa -in api/storage/jwt/jwt-private.pem -pubout -out api/storage/jwt/jwt-public.pem
```

Path default di `api/.env` sudah menunjuk ke file ini
(`JWT_PRIVATE_KEY=file://storage/jwt/jwt-private.pem`) — tak perlu diubah.

---

## 5. Reverse proxy, TLS & jalankan stack

Pilih **satu** varian sesuai kondisi server:

- **Varian A — standalone.** VPS kosong; flo-event memegang port 80/443 sendiri
  dan mengurus TLS lewat certbot bawaan.
- **Varian B — di belakang reverse proxy host.** VPS **sudah** menjalankan proxy
  lain di 80/443 (mis. stack `runup`). flo-event jalan di port localhost, proxy
  host yang merutekan per-domain + mengurus TLS. **Ini varian untuk srv1480624.**

> Cek dulu siapa yang memegang 80/443 dan proxy host-nya apa:
> ```bash
> sudo ss -ltnp | grep -E ':80 |:443 '
> ls /etc/nginx/sites-enabled/ 2>/dev/null; which caddy nginx
> ```
> Ada yang listen di `0.0.0.0:80/:443` → **Varian B**. Kosong → **Varian A**.

### Varian A — standalone

Service `nginx`+`certbot` ada di profile `standalone`; aktifkan dengan menambahkan
ke **root `.env`** (semua perintah `docker compose` berikutnya ikut memakainya):

```dotenv
COMPOSE_PROFILES=standalone
```

Terbitkan cert (rehearsal staging dulu — CA produksi hanya izinkan 5 gagal/jam),
lalu jalankan seluruh stack:

```bash
STAGING=1 ./init-letsencrypt.sh              # uji coba
docker compose down
docker volume rm flo-event_certbot_certs     # buang lineage staging
./init-letsencrypt.sh                        # cert asli
docker compose up -d --build                 # seluruh stack
```

### Varian B — di belakang reverse proxy host  ← srv1480624

flo-event **tidak** menyentuh 80/443. `docker-compose.shared.yml` mem-bind
`web`→`127.0.0.1:3001` dan `api`→`127.0.0.1:8001` (port 3000/8000/5432 sudah
dipakai runup), dan nginx/certbot flo-event tetap mati karena berada di profile
`standalone` yang tidak diaktifkan.

**1. Aktifkan mode shared** — tambahkan ke **root `.env`** agar setiap perintah
`docker compose` memakai kedua file:

```dotenv
COMPOSE_FILE=docker-compose.yml:docker-compose.shared.yml
```

**2. Build & jalankan** (nginx/certbot flo-event otomatis dilewati):

```bash
docker compose up -d --build
ss -ltnp | grep -E '3001|8001'          # harus muncul 127.0.0.1:3001 & :8001
curl http://127.0.0.1:8001/api/v1/health
```

**3. Pasang vhost di reverse proxy host.** Kalau proxy host = **nginx**:

```bash
sudo cp deploy/host-nginx/flo-event.conf /etc/nginx/sites-available/flo-event.conf
sudo ln -s /etc/nginx/sites-available/flo-event.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# TLS: certbot menulis blok 443 + redirect 80->443 otomatis
sudo certbot --nginx -d flo-event.flotech.id -d api-flo-event.flotech.id
```

Kalau proxy host = **Caddy**: pakai `deploy/host-caddy/flo-event.Caddyfile`
(import ke `/etc/caddy/Caddyfile`, lalu `sudo systemctl reload caddy`) — Caddy
mengurus TLS sendiri, tanpa certbot.

---

## 6. Pastikan semua service naik

```bash
docker compose ps          # semua flo-event-* → running/healthy
```

Container flo-event bernama `flo-event-*` (project `flo-event`), terpisah penuh
dari `runup_*`. Service `db`/`redis` flo-event hanya di jaringan internal Docker —
tidak mengekspos port host, jadi **tidak bentrok** dengan `runup_db` di
`127.0.0.1:5432` maupun Redis runup. Volume pun terpisah (`flo-event_pgdata`).

---

## 7. Migrasi & seeding database

Migrasi wajib. Untuk deploy pertama, DB juga perlu **katalog** (cabang olahraga,
definisi fitur, paket harga, testimoni, FAQ) — tanpa itu halaman Upgrade & landing
kosong dan pembuatan event gagal validasi.

```bash
# Skema
docker compose exec api php artisan migrate --force

# Seed HANYA data katalog/harga/landing (aman untuk produksi)
docker compose exec api php artisan db:seed --force --class=CatalogSeeder
docker compose exec api php artisan db:seed --force --class=FeatureDefinitionSeeder
docker compose exec api php artisan db:seed --force --class=PlanSeeder
docker compose exec api php artisan db:seed --force --class=TestimonialSeeder
docker compose exec api php artisan db:seed --force --class=FaqSeeder
```

> **Jangan jalankan `db:seed` polos di produksi.** `DatabaseSeeder` juga memanggil
> `UserSeeder` (membuat akun demo dengan password literal `"password"`, termasuk
> satu `super_admin`) dan `DemoEventSeeder` (event contoh). Semua seeder di atas
> idempoten (`updateOrCreate`), aman diulang saat rilis berikutnya.

### Buat akun super admin

Butuh satu `super_admin` untuk mengelola paket, settings, testimoni, FAQ, transfer
manual, dsb. Buat lewat tinker dengan password sendiri:

```bash
docker compose exec api php artisan tinker
```
```php
$u = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@flo-event.id',
    'password' => bcrypt('PASSWORD_KUAT_ANDA'),
    'email_verified_at' => now(),
]);
$u->assignRole('super_admin');   // role dari Catalog/permission seeder
```

---

## 8. Konfigurasi webhook Midtrans

Di dashboard Midtrans → **Settings → Configuration**, set **Payment Notification
URL** ke:

```
https://api-flo-event.flotech.id/api/v1/webhooks/midtrans
```

Signature diverifikasi di dalam controller. Tanpa ini, pembayaran gateway tidak
pernah tercatat lunas (dompet organizer & tiket tidak terbit).

---

## 9. Verifikasi

```bash
# Health check API
curl https://api-flo-event.flotech.id/api/v1/health

# Frontend
curl -I https://flo-event.flotech.id
```

Buka `https://flo-event.flotech.id` di browser — landing harus tampil dengan harga,
testimoni, dan FAQ dari database. Login sebagai super admin di `/admin`.

---

## Operasional

### Update / rilis versi baru
```bash
cd flo-event
git pull
docker compose up -d --build            # rebuild image yang berubah
docker compose exec api php artisan migrate --force
# ulangi seeder katalog bila ada perubahan paket/fitur/landing (idempoten)
```

> Kalau mengubah `web/.env.production` atau nilai `NEXT_PUBLIC_*`, wajib rebuild
> image `web` (`docker compose up -d --build web`) — nilainya di-inline saat build.

### Log
```bash
docker compose logs -f api
docker compose logs -f worker            # Horizon / queue
docker compose logs -f nginx
```

### Backup database (jadwalkan via cron host)
```bash
docker compose exec -T db pg_dump -U flo_user flo_event | gzip > backup-$(date +%F).sql.gz
```

Restore:
```bash
gunzip -c backup-YYYY-MM-DD.sql.gz | docker compose exec -T db psql -U flo_user -d flo_event
```

### Perintah dompet (lihat WALLET.md)
```bash
docker compose exec api php artisan wallet:backfill    # sekali, untuk order lunas lama
docker compose exec api php artisan wallet:audit       # cek invarian ledger
```
`wallet:release` (per jam), `tickets:expire-manual` (per jam), dan `wallet:audit`
(harian) dijalankan otomatis oleh service `scheduler`.

### Sertifikat TLS
Perpanjangan otomatis oleh service `certbot` tiap 12 jam. Setelah cert baru terbit,
reload nginx bila perlu:
```bash
docker compose exec nginx nginx -s reload
```

---

## Troubleshooting

| Gejala | Penyebab tersering |
|--------|--------------------|
| `api` restart terus, error "Unable to read key" | Kunci JWT `.pem` belum dibuat sebelum build (langkah 4). Buat lalu `docker compose up -d --build api worker scheduler`. |
| api tak bisa connect DB | `DB_USERNAME`/`DB_PASSWORD` di `api/.env` ≠ `POSTGRES_*` di `.env` root. |
| Error Redis / class not found | `REDIS_CLIENT` masih `phpredis`; ganti `predis`, recreate container. |
| nginx exit "host not found in upstream" | `web`/`api` belum jalan. `init-letsencrypt.sh` sudah menariknya via `depends_on`; jalankan lewat skrip itu, bukan `--no-deps`. |
| Rate limit Let's Encrypt | Terlalu banyak percobaan gagal. Rehearse dengan `STAGING=1` dulu. |
| Landing kosong (harga/FAQ/testimoni tak muncul) | Seeder katalog/landing belum dijalankan (langkah 7). |
| Email tak terkirim | `MAIL_MAILER` masih `log`; set `resend` + `RESEND_API_KEY`. |
| Pembayaran tak pernah lunas | Notification URL Midtrans belum diarahkan ke `/api/v1/webhooks/midtrans` (langkah 8). |

---

## Mode transfer manual (gateway mati)

Kalau Midtrans bermasalah, super_admin mematikan `payment_gateway_enabled` di
`/admin/settings`. Seluruh platform beralih ke transfer manual (pembeli transfer
langsung ke rekening organizer, unggah bukti, org admin meng-acc per event). Ini
sakelar runtime, bukan konfigurasi deploy — tidak perlu redeploy.
