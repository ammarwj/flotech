# 📋 Product Requirements Document (PRD)
## flo-event — Sports Event Management SaaS Platform
**Version:** 1.0.0
**Tanggal:** Juni 2026
**Status:** Draft
**Penulis:** Product Team

---

## Table of Contents

1. [Overview](#1-overview)
2. [Requirements](#2-requirements)
3. [Subscription & Pricing Model](#3-subscription--pricing-model)
4. [Core Features](#4-core-features)
5. [User Flow](#5-user-flow)
6. [Architecture](#6-architecture)
7. [Database Schema](#7-database-schema)
8. [Tech Stack](#8-tech-stack)
9. [Design Guidelines](#9-design-guidelines)
10. [Development Process Flow](#10-development-process-flow)

---

## 1. Overview

### 1.1 Executive Summary

**flo-event** adalah platform SaaS berbasis web untuk manajemen event olahraga end-to-end, dirancang untuk mendukung penyelenggara turnamen dari level komunitas lokal hingga turnamen profesional berskala nasional. Platform ini mengadopsi model subscription multi-tier sehingga organizer dapat memilih paket yang sesuai dengan skala dan kebutuhan event mereka.

Dengan flo-event, seluruh siklus penyelenggaraan turnamen — registrasi peserta, penjadwalan otomatis, input hasil real-time, klasemen dinamis, pembayaran online, tiket QR Code, generator sertifikat, hingga laporan akhir — dapat dikelola dalam satu platform terpadu.

### 1.2 Problem Statement

Penyelenggara turnamen olahraga menghadapi masalah berlapis:

**Operasional:**
- Registrasi peserta masih manual via WhatsApp / Google Form
- Jadwal pertandingan dibuat di spreadsheet, rawan error dan sulit dikomunikasikan
- Input hasil tidak terpusat, klasemen dihitung manual
- Tidak ada sistem tiket digital terintegrasi

**Profesionalisme:**
- Tidak ada platform tunggal yang bisa menangani turnamen skala kecil hingga profesional
- Sertifikat juara dibuat manual satu per satu
- Laporan akhir turnamen memakan waktu berhari-hari

**Skalabilitas Bisnis:**
- Tidak ada monetisasi yang jelas bagi platform
- Penyelenggara tidak mendapat insight data turnamen secara mendalam

### 1.3 Solution

flo-event hadir sebagai platform SaaS multi-tier yang:
- Menawarkan paket **Free** untuk komunitas kecil hingga paket **Professional** untuk turnamen berskala nasional
- Admin SaaS dapat mengkonfigurasi fitur per paket secara fleksibel melalui panel Super Admin
- Mendukung seluruh workflow turnamen dalam satu platform tanpa integrasi pihak ketiga yang rumit
- Generator sertifikat berbasis upload background — organizer cukup upload desain sertifikat mereka, lalu atur posisi teks/logo di atasnya

### 1.4 Target Users

| Segmen | Level | Deskripsi |
|--------|-------|-----------|
| **SaaS Super Admin** | Platform | Tim flo-event yang mengelola platform, paket, dan semua organizer |
| **Event Organizer** | Tenant | Penyelenggara turnamen (komunitas, kampus, klub, EO profesional, federasi) |
| **Tim Manager / Kapten** | Peserta | Perwakilan tim yang mendaftar dan mengelola roster |
| **Pemain** | Peserta | Individu anggota tim |
| **Penonton** | Publik | Masyarakat umum yang membeli tiket |
| **Operator Lapangan** | Staf Event | Petugas input hasil & scan check-in |

### 1.5 Cabang Olahraga (Tahap Awal)

| Ikon | Cabang | Format Didukung |
|------|--------|----------------|
| ⚽ | Sepak Bola | Liga, Knockout, Hybrid, Grup + Playoff |
| 🥅 | Futsal | Liga, Knockout, Hybrid |
| 🏸 | Badminton | Liga, Knockout, Round Robin |
| 🎾 | Padel | Liga, Knockout, Round Robin |
| 🏐 | Voli | Liga, Knockout, Pool Play |

> Daftar ini **bukan lagi hardcode**. Cabang olahraga, format turnamen, kolom statistik, tiebreaker, metode undian, dan tier sponsor kini tersimpan di database dan dikelola Super Admin (lihat §4.16). Menambah cabang baru tidak butuh deploy — selama **engine**-nya (liga / knockout / hybrid) sudah ada di kode.

### 1.6 Value Proposition

| Untuk | Value |
|-------|-------|
| **Organizer Pemula** | Mulai gratis, zero learning curve, setup event dalam 10 menit |
| **Organizer Profesional** | Kelola ratusan tim, tiket massal, laporan komprehensif, sertifikat otomatis |
| **Peserta** | Pendaftaran mudah, info real-time, jadwal di genggaman |
| **Penonton** | Beli tiket online, masuk dengan QR, pantau skor langsung |
| **SaaS Admin** | Pendapatan subscription + payment fee, data insight seluruh platform |

---

## 2. Requirements

### 2.1 Functional Requirements

#### FR-01: Multi-Tenant SaaS
- Setiap organizer adalah tenant terpisah dengan slug unik
- Isolasi data antar tenant (row-level scoping via `organization_id`)
- SaaS Super Admin memiliki akses lintas tenant untuk monitoring

#### FR-02: Subscription & Paket
- Organizer mendaftar dan memilih paket (Free / Starter / Pro / Professional)
- Akses fitur dikontrol berdasarkan paket aktif tenant
- SaaS Super Admin dapat membuat, mengubah, dan menonaktifkan paket beserta fiturnya
- Fitur per paket dikonfigurasi di database (bukan hardcode)
- Upgrade/downgrade paket mandiri dari dashboard organizer

#### FR-03: Manajemen Event
- Organizer dapat membuat event sesuai kuota paket
- Mendukung format: Liga, Knockout (single/double elimination), Hybrid
- Landing page publik per event dengan URL `flo-event.id/[org-slug]/[event-slug]`
- **Katalog event publik** di `flo-event.id/event` (pencarian, filter cabang/status, paginasi — semuanya di server) agar event bisa **ditemukan**, bukan hanya diakses lewat tautan langsung
- **Profil penyelenggara publik** di `flo-event.id/[org-slug]`, berisi profil organizer + seluruh event yang ia publikasikan

#### FR-04: Registrasi Tim & Peserta
- Form registrasi yang dapat dikonfigurasi organizer
- Upload dokumen peserta ke Cloudflare R2
- Approval manual / otomatis oleh organizer
- Payment gateway terintegrasi untuk biaya pendaftaran

#### FR-05: Manajemen Tim & Pemain
- Profil tim dengan logo dan data lengkap
- Roster pemain dengan foto dan data individu
- Lock roster setelah deadline organizer

#### FR-06: Jadwal Pertandingan
- Generate jadwal otomatis per format turnamen
- Edit jadwal manual dengan notifikasi ke peserta
- Kalender publik tanpa login

#### FR-07: Input Hasil Pertandingan
- Form input skor + statistik per cabang olahraga
- Workflow: Input Operator → Verifikasi Admin → Dikonfirmasi
- Auto-update klasemen & statistik setelah konfirmasi

#### FR-08: Klasemen Real-time
- Aturan klasemen yang dapat dikonfigurasi per cabang dan per event
- Update otomatis, halaman publik

#### FR-09: Statistik Pemain
- Akumulasi statistik individual per event
- Leaderboard: top scorer, top assist, MVP, fair play

#### FR-10: Bracket Turnamen
- Visualisasi bracket knockout interaktif, auto-update
- Sharable & embeddable link

#### FR-11: Dashboard Admin
- Multi-role: Super Admin SaaS, Event Admin, Operator
- KPI real-time per event, manajemen semua modul

#### FR-12: Generator Sertifikat
- Organizer upload file background sertifikat (JPG/PNG) ke R2
- Atur posisi elemen di atas background dengan **drag & drop** (koordinat persen): nama penerima, nama tim, penghargaan, nama event, tanggal, penyelenggara, nomor sertifikat, QR
- Kanvas editor **adalah** previewnya — geometrinya identik dengan renderer PDF
- Terbitkan batch PDF per penerima (tim yang disetujui atau pemain aktif), idempoten per `(event, penerima, penghargaan)`
- Output disimpan di R2, dapat didownload; opsional dikirim via email (queue, paket Pro ke atas)
- Setiap sertifikat punya nomor unik + QR ke halaman verifikasi publik

#### FR-13: Export Excel / PDF
- Export jadwal, klasemen, statistik, data peserta
- Laporan keuangan dan laporan turnamen komprehensif

#### FR-14: Payment Gateway
- Integrasi Midtrans untuk semua transaksi
- **Semua pembayaran masuk ke satu akun merchant milik platform** (tidak ada split payment / sub-merchant per organizer)
- Platform fee dihitung & dicatat per transaksi sesuai paket organizer
- Refund dikelola Super Admin

#### FR-15: Tiket QR Code + Scan Check-in
- Tiket digital dengan QR Code unik per tiket
- Halaman scan berbasis kamera (no app install)
- Validasi server-side one-time use

#### FR-16: Laporan Turnamen
- Laporan end-of-tournament: klasemen akhir, statistik, ringkasan keuangan
- Export PDF & Excel, share link online

#### FR-17: Dompet Organizer & Penarikan Dana
- Karena uang pembeli mendarat di akun platform (FR-14), bagian organizer ditampung di **dompet** per organisasi
- Pemasukan = penjualan tiket + biaya pendaftaran tim, dikreditkan **neto** (bruto − platform fee)
- Dana **tertahan** sampai event selesai, lalu jadi **tersedia** untuk ditarik
- Organizer mengajukan penarikan ke rekening bank; Super Admin transfer manual lalu mencatat bukti transfer
- Ledger immutable + audit otomatis; refund mengoreksi saldo organizer

#### FR-18: Katalog & Pengaturan Platform
- Cabang olahraga, format turnamen, kolom statistik, tiebreaker, metode undian, tier sponsor: dikelola Super Admin dari panel, tanpa deploy
- Aturan pencairan (minimal penarikan, biaya admin, masa tahan) dapat diubah Super Admin dan berlaku instan untuk penarikan baru

#### FR-19: Media Event (Galeri & Sponsor)
- Album foto per event dan logo sponsor bertingkat (tier), tampil di landing page publik

### 2.2 Non-Functional Requirements

| Kategori | Requirement |
|----------|-------------|
| **Performance** | Halaman load < 2 detik (LCP), API response < 300ms p95 |
| **Scalability** | Mendukung hingga 1.000 tim dan 50.000 penonton per event |
| **Availability** | Uptime 99.9% (VPS dengan monitoring + auto-restart) |
| **Security** | HTTPS, JWT RS256, data encryption, OWASP Top 10 |
| **Usability** | Mobile-responsive (PWA), semua browser modern |
| **File Storage** | Semua file di Cloudflare R2 |
| **Data Privacy** | Compliance UU PDP Indonesia |
| **Audit Trail** | Log semua aksi penting (input hasil, konfirmasi, pembayaran) |

### 2.3 Constraints

- Backend: **Laravel 13** (PHP 8.4+, min 8.3)
- Frontend: **Next.js 16** (React 19, Node.js 20.9+)
- Autentikasi: **JWT RS256** stateless
- File storage: **Cloudflare R2** (S3-compatible)
- Payment gateway: **Midtrans**
- Deployment: **1 VPS**, semua service via **Docker Compose**
- Bahasa platform: Indonesia

---

## 3. Subscription & Pricing Model

### 3.1 Paket Langganan

| Fitur | Free | Starter | Pro | Professional |
|-------|:----:|:-------:|:---:|:------------:|
| **Harga/bulan** | Rp 0 | Rp 149.000 | Rp 399.000 | Rp 999.000 |
| **Max Event Aktif** | 1 | 3 | 10 | Unlimited |
| **Max Tim per Event** | 8 | 32 | 128 | Unlimited |
| **Landing Page Event** | ✅ | ✅ | ✅ | ✅ |
| **Registrasi Tim** | ✅ | ✅ | ✅ | ✅ |
| **Jadwal & Klasemen** | ✅ | ✅ | ✅ | ✅ |
| **Bracket Turnamen** | ✅ | ✅ | ✅ | ✅ |
| **Input Hasil** | ✅ | ✅ | ✅ | ✅ |
| **Statistik Pemain** | Basic | ✅ | ✅ | ✅ |
| **Payment Gateway** | ❌ | ✅ | ✅ | ✅ |
| **Tiket QR Code** | ❌ | ✅ | ✅ | ✅ |
| **Max Tiket per Event** | — | 500 | 5.000 | Unlimited |
| **Export Excel** | ❌ | ✅ | ✅ | ✅ |
| **Export PDF** | ❌ | ✅ | ✅ | ✅ |
| **Generator Sertifikat** | ❌ | ✅ | ✅ | ✅ |
| **Upload Background Kustom** | ❌ | ✅ | ✅ | ✅ |
| **Kirim Sertifikat via Email** | ❌ | ❌ | ✅ | ✅ |
| **Laporan Turnamen** | ❌ | Basic | Full | Full + Custom |
| **Custom Subdomain** | ❌ | ❌ | ✅ | ✅ |
| **Custom Domain** | ❌ | ❌ | ❌ | ✅ |
| **Max Operator** | 1 | 3 | 10 | Unlimited |
| **Storage** | 2 GB | 10 GB | 50 GB | 200 GB |
| **Priority Support** | ❌ | ❌ | ✅ | ✅ (Dedicated) |
| **White Label** | ❌ | ❌ | ❌ | ✅ |
| **Platform Fee Tiket** | — | 3% | 2% | 1% |

> **Catatan:** Seluruh batas dan fitur per paket dapat diubah oleh SaaS Super Admin melalui panel admin tanpa perubahan kode.

### 3.2 Mengapa Storage Dibatasi

Storage R2 adalah satu-satunya resource yang berbayar per GB. Estimasi 1 event turnamen ukuran sedang (32 tim, 20 pemain) menghasilkan sekitar 100–200 MB (foto pemain, logo tim, dokumen registrasi, sertifikat, laporan). Batas storage berfungsi sebagai **guardrail** agar tidak ada tenant yang menguras bucket secara tidak wajar, bukan sebagai monetisasi utama. Harga R2 ~$0.015/GB/bulan, sehingga 200 GB hanya ~$3/bulan di sisi platform.

### 3.3 Add-on (Opsional)

| Add-on | Harga |
|--------|-------|
| Tambahan storage 10 GB | Rp 50.000/bulan |
| Tambahan event aktif +1 | Rp 50.000/bulan |
| Custom domain setup | Rp 99.000 (one-time) |
| Dedicated onboarding | Rp 500.000 |

### 3.4 Billing Model

- **Siklus:** Bulanan atau tahunan (diskon 20% tahunan)
- **Payment:** Midtrans (kartu kredit, transfer bank, QRIS)
- **Auto-renewal** dengan notifikasi 7 hari sebelum perpanjangan
- **Grace period** 7 hari setelah jatuh tempo sebelum downgrade ke Free
- **Downgrade:** Fitur terkunci, data tetap aman

### 3.5 SaaS Admin — Konfigurasi Paket

Setiap feature flag disimpan di database:

```
feature_key       → identifier unik: "max_events", "certificate_email", dll.
feature_type      → boolean | numeric | text
value per paket   → { free: "1", starter: "3", pro: "10", professional: "-1" }
                    (-1 = unlimited)
```

SaaS Super Admin dapat mengubah nilai ini kapan saja via panel admin, berlaku instan untuk semua tenant di paket tersebut tanpa deploy ulang.

Platform fee juga berupa feature value per paket: `ticket_fee_percent` dan `registration_fee_percent` (mis. Pro 3%, Business 2%, Enterprise 1%). Nilai yang berlaku **disimpan di setiap transaksi**, jadi mengubah paket tidak menulis ulang fee transaksi lama.

### 3.6 SaaS Admin — Pengaturan Pencairan

Terpisah dari paket, karena ini kebijakan **platform**, bukan per-tenant. Disimpan di tabel `platform_settings` dengan default dari `config/wallet.php`:

| Setting | Contoh | Batas |
|---------|--------|-------|
| Minimal penarikan | Rp 100.000 | 0 – 100.000.000 |
| Biaya admin per penarikan | Rp 5.000 | 0 – 1.000.000 |
| Masa tahan setelah event selesai | 0 hari | 0 – 90 |

Perubahan berlaku untuk penarikan **baru**. Penarikan yang sudah diajukan menyimpan snapshot aturan yang berlaku saat itu, sehingga riwayat tidak pernah ditulis ulang.

---

## 4. Core Features

### 4.1 Landing Page Event

Halaman publik per event, URL: `flo-event.id/[org-slug]/[event-slug]`

**Komponen:**
- Hero: nama event, banner, tanggal, lokasi, countdown timer
- Info cabang olahraga, format turnamen, total tim terdaftar
- CTA: "Daftar Sekarang" / "Beli Tiket"
- Jadwal pertandingan dan klasemen live (publik)
- Sponsor & partner section
- Kontak penyelenggara

---

### 4.2 Registrasi Tim / Peserta

**Alur:**
1. Peserta buka landing page → klik "Daftar"
2. Isi form registrasi (field dikonfigurasi organizer)
3. Input data tim + roster pemain + upload dokumen (langsung ke R2 via signed URL)
4. Generate invoice → peserta bayar (jika berbayar)
5. Organizer approve/reject
6. Email konfirmasi + akses dashboard tim

---

### 4.3 Data Tim & Pemain

**Data Tim:** nama, logo (R2), warna kostum, kota asal, kontak kapten

**Data Pemain:** nama, nomor punggung, posisi, tanggal lahir, foto (R2)

**Kontrol:** tim manager edit data hingga deadline, organizer dapat lock roster dan diskualifikasi tim

---

### 4.4 Jadwal Pertandingan

- Generate otomatis: round-robin, knockout, hybrid
- Edit manual: tanggal, waktu, lapangan/venue
- Tampilan publik: list view + kalender view, filter per hari/grup/babak
- Status: Upcoming / Live / Selesai / Ditunda

---

### 4.5 Input Hasil Pertandingan

**Data per cabang:**

| Cabang | Data yang Diinput |
|--------|------------------|
| Sepak Bola / Futsal | Skor, pencetak gol (menit), assist, kartu kuning/merah |
| Badminton | Skor set (21-poin), pemenang per game |
| Padel | Skor game per set, tie-break |
| Voli | Skor set (25-poin), set ke-5 (15-poin) |

**Workflow:**
```
Operator Input → [Pending Konfirmasi] → Admin Review → [Dikonfirmasi]
                                                          ↓
                                           Klasemen & Statistik auto-update
```

---

### 4.6 Klasemen

Aturan default per cabang, semua dapat dikonfigurasi organizer per event.

| Cabang | Menang | Seri | Kalah | Tiebreaker |
|--------|--------|------|-------|-----------|
| Sepak Bola / Futsal | 3 poin | 1 | 0 | Selisih gol → Gol masuk → Head-to-head |
| Voli | 3 (3-0/3-1) atau 2 (3-2) | — | 1 (2-3) atau 0 | Rasio set → Rasio poin |
| Badminton / Padel | 2 | — | 0 | Rasio game → Rasio poin |

---

### 4.7 Statistik Pemain

| Cabang | Statistik |
|--------|-----------|
| Sepak Bola / Futsal | Gol, Assist, Kartu Kuning, Kartu Merah, Menit Main |
| Badminton | Games Menang, Games Kalah, Poin Dibuat, Ace |
| Padel | Games Menang, Sets Menang, Ace |
| Voli | Poin Smash, Poin Servis, Block, Error |

Leaderboard: Top Scorer, Top Assist, MVP, Fair Play Award

---

### 4.8 Bracket Turnamen

- Single & Double Elimination, Hybrid
- Visualisasi interaktif, responsive, auto-update
- Highlight jalur tim, share link & embed code

---

### 4.9 Dashboard Admin

**Role & Scope:**

| Role | Scope |
|------|-------|
| SaaS Super Admin | Seluruh platform: tenant, paket, billing, laporan global |
| Organization Admin | Semua event dalam organisasi |
| Event Admin | Event tertentu yang ditugaskan |
| Operator | Input hasil + scan tiket (per event) |
| Tim Manager | Data tim sendiri |

---

### 4.10 Generator Sertifikat

**Konsep:** Organizer membawa desain sertifikat mereka sendiri (dalam bentuk gambar), flo-event hanya bertugas mencetak data penerima di atas desain tersebut secara otomatis.

**Gating paket:** `certificate_generator` (Starter ke atas) untuk template & generate; `certificate_email` (Pro ke atas) untuk pengiriman email. Ditolak dengan **403 + `errors.feature`** di controller, bukan diam-diam diabaikan.

**Alur Setup Template:**
1. Organizer upload file background sertifikat (JPG/PNG) — endpoint upload me-re-encode ke WebP dan menyimpannya di R2 (`certificates/`)
2. Background tampil sebagai kanvas; organizer **menggeser** setiap field ke posisinya (koordinat X/Y juga bisa diketik manual)
3. Field yang bisa ditempatkan **berasal dari katalog** `config/certificate.php` — bukan daftar hardcode di UI:
   `recipient_name`, `team_name`, `award_title`, `event_name`, `event_date`, `organization_name`, `certificate_number`, `qr`
4. Per field: ukuran font (pt), warna, alignment (kiri/tengah/kanan), tebal, huruf kapital
5. Simpan → template milik **organisasi** (bukan per event), jadi satu desain bisa dipakai lintas musim

> **Catatan implementasi:** tanda tangan dan logo tidak menjadi field terpisah — keduanya sudah menyatu di artwork yang diunggah organizer. Preview tidak memakai render server: kanvas editor memakai **geometri identik dengan renderer PDF** (anchor per alignment, font pt diskalakan ke lebar halaman), sehingga yang terlihat di layar sama dengan yang tercetak.

**Alur Generate Sertifikat:**
```
[Event Selesai]
  → Dashboard → Sertifikat → "Terbitkan"
  → Pilih event + template + judul penghargaan (teks bebas, mis. "Juara 1")
  → Pilih penerima: tim yang disetujui, atau pemain aktif di tim-tim itu
  → Klik "Terbitkan"
  → [CertificateService, sinkron]
      → Ambil background sekali (dibaca langsung dari bucket, di-re-encode ke JPEG)
      → Render PDF A4 per penerima (dompdf; teks + QR vektor di atas background)
      → Upload ke R2: certificates/{certificate-id}.pdf
  → Daftar sertifikat + tombol Download PDF per baris
  → Opsional: "Kirim ke email penerima" → [Queue: SendCertificateJob] (paket Pro ke atas)
```

**Idempoten:** unique `(event, penerima, penghargaan)` — menjalankan ulang batch setelah menambah tim tidak akan menerbitkan "Juara 1" kedua untuk tim yang sama; yang sudah punya dilewati, bukan menggagalkan batch.

**Jenis penghargaan:** teks bebas yang diketik organizer (Juara 1, Top Scorer, Peserta, …) — tidak ada daftar tertutup di sistem.

**Data Auto-populate:**
- Nama penerima (tim atau pemain) + nama tim (untuk sertifikat pemain)
- Nama event & tanggal pelaksanaan, nama penyelenggara
- Judul penghargaan
- Nomor sertifikat unik: `CERT-2026-07-0001` (lihat Lampiran B)
- QR Code verifikasi → `flo-event.id/verify/CERT-2026-07-0001`

**Email penerima:** tim dan pemain **tidak punya kolom email**. Alamat tujuan diambil dari akun **manajer tim** (`teams.manager_user_id`); tim yang didaftarkan tanpa akun tidak punya email dan tombol kirimnya nonaktif.

**Kendala renderer yang menentukan desain (jangan diutak-atik tanpa alasan):**

1. **PDF dirender dompdf, bukan Intervention Image.** Sertifikat butuh teks tajam yang bisa diseleksi dan QR vektor — bukan gambar raster yang pecah saat dicetak. Konsekuensinya, tata letak terbatas pada subset CSS lama dompdf: **absolute positioning, tanpa flexbox/grid/transform**.
2. **dompdf tidak bisa membaca WebP**, padahal endpoint upload justru menyimpan gambar sebagai WebP. Karena itu background di-**re-encode ke JPEG** (Intervention/GD) sebelum ditempel sebagai data URI. Ini juga alasan gambar tidak dibiarkan diambil dompdf lewat URL: byte-nya dibaca **langsung dari bucket** (sekali per batch, bukan sekali per sertifikat), sekaligus menghindari egress dan verifikasi TLS ke host publik R2.

---

### 4.11 Export Excel / PDF

**Excel (.xlsx):** data registrasi, jadwal, klasemen, statistik pemain, laporan keuangan

**PDF:** laporan turnamen resmi, bracket akhir, invoice, daftar hadir

---

### 4.12 Payment Gateway (Midtrans)

**Metode:** Virtual Account (BCA/BNI/BRI/Mandiri), QRIS, GoPay/OVO/DANA/ShopeePay, Kartu Kredit/Debit

**Transaksi:** biaya subscription SaaS, biaya registrasi tim, pembelian tiket penonton

**Semua uang masuk ke satu akun merchant Midtrans milik platform.** Tidak ada split payment maupun sub-merchant per organizer — pembeli membayar ke platform, bukan ke organizer.

**Platform fee** dihitung dari paket organizer (`ticket_fee_percent` / `registration_fee_percent`) dan **dicatat di setiap transaksi**. Sisanya (neto) menjadi hak organizer dan ditampung di dompet (§4.15).

---

### 4.13 Tiket QR Code + Scan Check-in

**Pembelian:** pilih kategori tiket → isi data → bayar → terima e-tiket via email (QR Code unik)

**Check-in:** operator buka `flo-event.id/scan/[event-id]` → scan QR → validasi server-side → ✅ Valid / ❌ Sudah digunakan

---

### 4.14 Laporan Turnamen

Konten: ringkasan event, klasemen akhir, bracket hasil, top statistik, rekap keuangan

Output: PDF siap cetak, Excel arsip, share link online

---

### 4.15 Dompet Organizer & Penarikan Dana

Konsekuensi langsung dari §4.12: uang pembeli ada di akun platform, jadi harus ada mekanisme meneruskannya ke organizer.

**Siklus dana**

```
Pembeli bayar (Midtrans → akun platform)
  → kredit NETO ke dompet organizer (bruto − platform fee)
    status: TERTAHAN
  → event selesai  ──►  status: TERSEDIA
  → organizer ajukan penarikan (dana langsung ditahan)
  → Super Admin transfer MANUAL via m-banking + upload bukti
  → status: SELESAI
```

**Kenapa dana ditahan sampai event selesai:** kalau terjadi refund saat kredit masih tertahan, kreditnya cukup **dibatalkan** — tanpa perlu menarik balik uang yang sudah dicairkan.

**Aturan penarikan:** minimal penarikan, biaya admin tetap per penarikan (dipotong dari saldo), dan maksimal **1 permintaan aktif** per organisasi. Nilainya diatur Super Admin (§3.6).

**Refund** (Super Admin): membatalkan pesanan dan mengoreksi saldo organizer. Kalau dananya sudah dicairkan **dan** ditarik, saldo bisa **minus** — ini disengaja, dan otomatis mengunci penarikan berikutnya sampai tertutup pendapatan baru.

> ⚠️ Refund **tidak** mengembalikan uang ke pembeli secara otomatis. Uangnya ada di akun Midtrans platform, jadi refund harus diproses juga di dashboard Midtrans.

**Integritas:** setiap pergerakan uang tercatat di ledger immutable (`wallet_transactions`). Saldo didenormalisasi agar penarikan bisa dicek + dikunci dalam satu baris, dan perintah audit harian memastikan saldo selalu sama dengan jumlah ledger.

Detail teknis lengkap: **`WALLET.md`**.

---

### 4.16 Katalog & Konfigurasi Platform

Cabang olahraga dan vokabuler turnamen tidak lagi hardcode — semuanya data yang dikelola Super Admin:

| Dikelola | Contoh |
|----------|--------|
| Cabang olahraga | Sepak Bola, Futsal, Badminton, Padel, Voli (+ warna, durasi match default, set-based atau tidak) |
| Kolom statistik per cabang | gol, assist, kartu, ace, smash… |
| Format turnamen | Liga, Liga 2 Putaran, Knockout, Hybrid, Grup + Playoff |
| Tiebreaker, metode undian, ronde knockout, tier sponsor | — |

**Format adalah preset di atas engine.** Engine (`league`, `knockout`, `hybrid`) hidup di kode; admin bisa membuat preset "Liga 2 Putaran" (engine `league`, default `legs: 2`) tanpa deploy — tapi **tidak bisa** mengarang engine yang tidak ada implementasinya.

---

### 4.17 Media Event (Galeri & Sponsor)

**Galeri:** album foto per event, upload ke R2, tampil di landing page publik.

**Sponsor:** logo sponsor dengan **tier** (mis. Platinum/Gold/Silver) yang menentukan ukuran & urutan tampil di landing page. Daftar tier dikelola lewat katalog (§4.16).

---

### 4.18 Katalog Event Publik

Halaman publik `flo-event.id/event` — pintu masuk bagi orang yang **belum tahu** event apa saja yang ada. Tanpa ini, landing page event (§4.1) hanya bisa dicapai kalau seseorang sudah memegang tautannya.

- **Isi:** semua event dengan `status != 'draft'` — aturan visibilitas yang sama persis dengan landing page event, jadi tidak ada dua definisi "publik" yang harus dijaga. Event `finished` dan `cancelled` tetap tampil (dengan badge status) sebagai arsip.
- **Urutan:** event yang masih bisa ditindaklanjuti (`open`, `registration_closed`, `ongoing`) di atas, lalu yang terbaru.
- **Pencarian & filter di server:** `?search=` (nama event, lokasi, atau nama penyelenggara), `?sport=`, `?status=`, `?org=`, dengan paginasi. State filter disinkron ke URL supaya `/event?sport=futsal` bisa dibagikan.
- Kartu event → tautan ke `/{org-slug}/{event-slug}`.

> Halaman demo statis lama ("Contoh Event") dipindah ke `/event/demo` dan tetap dipakai sebagai showcase untuk calon organizer.

---

### 4.19 Profil Penyelenggara Publik

Halaman publik `flo-event.id/{org-slug}` — profil organizer beserta seluruh event yang ia publikasikan.

- **Isi:** banner, logo, nama, deskripsi, kontak, social links, jumlah event, dan grid event-nya (memakai ulang endpoint katalog dengan filter `?org=`).
- Nama penyelenggara di landing page event menautkan ke sini.
- Banner, logo, deskripsi, kontak, dan social links diedit organizer di **Pengaturan Organisasi**.

**Social links — satu aturan yang menentukan seluruh alurnya:** organizer boleh mengetik apa saja (`@klubku`, `instagram.com/klubku`, atau URL penuh). Ketiganya **dinormalisasi menjadi URL profil lengkap saat validasi** (`UpdateOrganizationRequest`), memakai base URL dari `Organization::SOCIAL_PLATFORMS`. Konsekuensinya: yang tersimpan **selalu berupa tautan**, sehingga form settings maupun halaman publik cukup me-render `<a>` tanpa pernah menebak-nebak platform. Platform yang tidak diisi disimpan `null` (bentuk map-nya stabil untuk form), tapi **dibuang dari payload publik** — halaman publik hanya menerima platform yang benar-benar diisi, jadi tidak ada ikon kosong yang perlu di-skip.

**Slug tak dikenal wajib menjawab HTTP 404 sungguhan.** Route ini duduk di root, jadi ia menangkap *setiap* path tak dikenal (`/pricingg`, typo apa pun). Karena itu profilnya di-fetch **di server** (Next.js server component → `notFound()`); kalau di-fetch di klien, semua URL ngawur akan menjawab 200 dan terindeks mesin pencari. Grid event-nya tetap client-side.

---

## 5. User Flow

### 5.1 Organizer — Onboarding & Buat Event

```
[Daftar Akun] → [Verifikasi Email]
  → [Dashboard — Welcome]
  → Pilih Paket → [Bayar Subscription] (jika berbayar)
  → [Buat Event Baru]
      → Detail event, cabang, format, konfigurasi registrasi
      → Setup tiket (opsional)
      → Publish Event
  → [Landing Page Aktif]
  → Monitor pendaftaran → Approve/Reject tim
  → Generate jadwal
  → [Event Berlangsung]
  → Konfirmasi hasil pertandingan
  → [Event Selesai] → Generate Sertifikat → Laporan Akhir
```

### 5.2 Peserta — Daftar Tim

```
[Buka Landing Page Event]
  → "Daftar Sekarang" → [Buat Akun / Login]
  → Isi Form Registrasi (nama tim, logo, kota, data pemain)
  → Upload dokumen → Submit
  → [Bayar] (jika berbayar) → Konfirmasi
  → [Menunggu Approval]
  → [Email: Disetujui/Ditolak]
  → Dashboard Tim → Lihat jadwal & update roster
```

### 5.3 Operator — Input Hasil

```
[Login Operator]
  → Daftar pertandingan hari ini
  → Pilih pertandingan → "Input Hasil"
  → Input skor + statistik → Submit → [Pending]
  → Admin konfirmasi → [Dikonfirmasi]
  → Klasemen & statistik auto-update
```

### 5.4 Penonton — Beli Tiket & Check-in

```
[Landing Page Event] → "Beli Tiket"
  → Pilih kategori & jumlah → Isi data → Bayar
  → [Terima E-Tiket via Email + QR Code]
  → [Hari H] Tunjukkan QR ke operator → Scan → ✅ Masuk
```

### 5.5 Organizer — Setup & Generate Sertifikat

```
[Dashboard] → Sertifikat                      (paket tanpa fitur → panel upgrade)
  → Tab "Template" → "Template baru"
      → Upload file background sertifikat (JPG/PNG)
      → Geser tiap field ke posisinya di atas kanvas (X/Y juga bisa diketik)
          • Nama penerima, nama tim, penghargaan
          • Nama event, tanggal, penyelenggara
          • Nomor sertifikat, QR verifikasi
      → Atur font size (pt), warna, alignment, tebal, kapital per field
      → Simpan template  (dipakai ulang lintas event)
  → [Event Selesai] → "Terbitkan"
      → Pilih event + template + judul penghargaan
      → Pilih penerima: tim disetujui / pemain aktif  ("Pilih semua" tersedia)
      → Centang "Kirim ke email penerima" (Pro ke atas; mati kalau paket belum mencakup)
      → Terbitkan → PDF langsung jadi & tersimpan
  → Tab "Diterbitkan" → Download PDF per sertifikat, kirim ulang email, hapus
  → Penerima memindai QR → /verify/{nomor} → halaman verifikasi publik
```

### 5.6 SaaS Super Admin — Kelola Paket & Katalog

```
[Login Super Admin]
  → Dashboard Platform (MRR, total tenant, event aktif)
  → Menu "Kelola Paket" → Pilih/buat paket
  → Edit feature values:
      max_events: 10 | certificate_email: true | storage_gb: 50
      ticket_fee_percent: 3 | registration_fee_percent: 2
  → Simpan → Berlaku instan untuk semua tenant di paket tersebut

  → Menu "Cabang Olahraga" / "Opsi Konfigurasi"
      Tambah cabang baru, kolom statistik, format turnamen (preset di atas engine)
  → Menu "Pengaturan Platform"
      Minimal penarikan, biaya admin, masa tahan → berlaku untuk penarikan baru
```

---

### 5.7 Organizer — Tarik Dana

```
[Event selesai]
  → Dana pindah dari "Saldo Tertahan" ke "Saldo Tersedia"
  → Menu "Dompet" → cek saldo & mutasi
  → Tambah rekening bank (sekali saja)
  → "Tarik Dana" → isi jumlah
      Sistem tampilkan: kamu terima Rp X · biaya admin Rp Y · total dipotong Rp X+Y
  → Ajukan → saldo LANGSUNG berkurang (dana ditahan)
  → Tunggu admin transfer → status Selesai + bukti transfer bisa dilihat
```

Tombol "Tarik Dana" **dimatikan dengan alasan eksplisit** kalau: rekening belum ada / masih ada penarikan berjalan / saldo di bawah minimal / saldo minus.

---

### 5.8 SaaS Super Admin — Proses Pencairan

```
[Login Super Admin]
  → Menu "Penarikan Dana" → antrian status "Menunggu"
  → Salin nomor rekening → transfer MANUAL via m-banking
  → "Proses" → "Tandai Selesai" + upload bukti transfer (wajib)
  → Organizer melihat status Selesai + bukti

  (atau) → "Tolak" + alasan → dana kembali ke saldo tersedia organizer
```

---

## 6. Architecture

### 6.1 High-Level Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                         CLIENT LAYER                             │
│          Next.js 16 Web App (SSR + CSR)  |  PWA Mobile          │
└─────────────────────────────┬────────────────────────────────────┘
                              │ HTTPS
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│                      CLOUDFLARE EDGE                             │
│           DNS + CDN + DDoS Protection + R2 Storage               │
└─────────────────────────────┬────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│                    VPS — Docker Compose                          │
│  ┌───────────────────────────────────────────────────────────┐   │
│  │                   Nginx (Reverse Proxy)                    │   │
│  │   flo-event.id → Next.js   |   api.flo-event.id → Laravel │   │
│  └──────────────────┬─────────────────────┬──────────────────┘   │
│                     │                     │                       │
│          ┌──────────▼──────┐   ┌──────────▼────────┐            │
│          │  Next.js App    │   │  Laravel API       │            │
│          │  (web container)│   │  (api container)   │            │
│          │  Port: 3000     │   │  Port: 8000        │            │
│          └─────────────────┘   └──────┬────────────-┘            │
│                                       │                           │
│              ┌────────────────────────┼───────────────┐          │
│              │                        │               │           │
│   ┌──────────▼─────┐   ┌─────────────▼──┐  ┌─────────▼──────┐  │
│   │  PostgreSQL     │   │     Redis      │  │  Queue Worker  │  │
│   │  (db container) │   │  (cache+queue) │  │  (worker cont) │  │
│   │  Port: 5432     │   │  Port: 6379    │  │  Horizon       │  │
│   └─────────────────┘   └────────────────┘  └────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
                              │
               ┌──────────────┴──────────────┐
               ▼                             ▼
┌──────────────────────┐       ┌─────────────────────────────────┐
│   Cloudflare R2      │       │       External Services          │
│   (File Storage)     │       │  Midtrans | Resend | Sentry      │
└──────────────────────┘       └─────────────────────────────────┘
```

### 6.2 Monorepo Structure

```
flo-event/                          ← root monorepo
├── web/                            ← Next.js 16 frontend
├── api/                            ← Laravel 13 backend
├── docker-compose.yml              ← orchestrasi semua service
├── docker-compose.dev.yml          ← override untuk development
├── .env.example                    ← template environment variables
├── .gitignore
└── README.md
```

### 6.3 Docker Compose Services

```yaml
# docker-compose.yml — gambaran umum

services:

  nginx:                            # Reverse proxy
    image: nginx:alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d
      - certbot_certs:/etc/letsencrypt
    depends_on: [web, api]

  web:                              # Next.js frontend
    build: ./web
    environment:
      - NEXT_PUBLIC_API_URL=https://api.flo-event.id
    depends_on: [api]

  api:                              # Laravel backend
    build: ./api
    environment:
      - DB_HOST=db
      - REDIS_HOST=redis
      - APP_ENV=production
    depends_on: [db, redis]

  worker:                           # Laravel Queue Worker (Horizon)
    build: ./api
    command: php artisan horizon
    depends_on: [db, redis]

  scheduler:                        # Laravel Scheduler
    build: ./api
    command: php artisan schedule:work
    depends_on: [db, redis]

  db:                               # PostgreSQL
    image: postgres:17-alpine
    volumes:
      - pgdata:/var/lib/postgresql/data
    environment:
      - POSTGRES_DB=flo_event
      - POSTGRES_USER=flo_user

  redis:                            # Redis
    image: redis:8-alpine
    volumes:
      - redisdata:/data

volumes:
  pgdata:
  redisdata:
  certbot_certs:
```

### 6.4 Frontend Structure (web/)

```
web/
├── app/
│   ├── (public)/                   # Route tanpa auth
│   │   ├── event/                  # Katalog event publik (§4.18)
│   │   │   └── demo/               # Halaman contoh event (statis, showcase)
│   │   ├── [orgSlug]/              # Profil penyelenggara (§4.19) — SERVER component (404 asli)
│   │   │   └── [eventSlug]/        # Landing page event (+ /register, /tickets)
│   │   ├── verify/
│   │   │   └── [number]/           # Verifikasi sertifikat publik
│   │   └── tickets/
│   │       └── [orderId]/          # E-tiket pembeli
│   ├── (auth)/                     # Login, Register, Reset Password
│   ├── (dashboard)/                # Protected routes
│   │   ├── organizer/              # Dashboard organizer
│   │   │   ├── events/
│   │   │   ├── teams/
│   │   │   ├── schedule/
│   │   │   ├── results/
│   │   │   ├── standings/
│   │   │   ├── tickets/
│   │   │   ├── certificates/       # Tab: Diterbitkan | Template
│   │   │   │   ├── generate/       # Wizard terbitkan batch
│   │   │   │   └── templates/      # new/ + [id]/ → editor drag & drop
│   │   │   ├── wallet/
│   │   │   ├── upgrade/            # Checkout paket (satu-satunya halaman billing)
│   │   │   ├── reports/
│   │   │   └── settings/
│   │   ├── participant/            # Dashboard peserta/tim
│   │   └── admin/                  # SaaS Super Admin panel
│   └── api/                        # Next.js API routes (cookie refresh)
├── components/
│   ├── ui/                         # shadcn/ui base components
│   ├── shared/                     # Layout, navbar, sidebar
│   ├── event/                      # Event-specific components
│   ├── bracket/                    # Bracket visualisasi
│   ├── certificate/                # Certificate positioner + preview
│   └── scanner/                    # QR Code scanner
├── lib/
│   ├── api/                        # Axios client + JWT interceptor
│   ├── auth/                       # Auth helpers, token management
│   └── utils/                      # Shared utilities
├── stores/                         # Zustand stores
├── types/                          # TypeScript type definitions
├── Dockerfile
└── next.config.ts
```

### 6.5 Backend Structure (api/)

```
api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/               # Login, register, refresh JWT
│   │   │   ├── Admin/              # SaaS Super Admin
│   │   │   ├── Organization/       # Org management
│   │   │   ├── Event/              # Event CRUD
│   │   │   ├── Team/               # Team & player
│   │   │   ├── Match/              # Schedule & results
│   │   │   ├── Standing/           # Standings
│   │   │   ├── Ticket/             # Ticket & check-in
│   │   │   ├── Certificate/        # Certificate generator
│   │   │   ├── Payment/            # Midtrans
│   │   │   ├── Export/             # Excel & PDF export
│   │   │   └── Webhook/            # Midtrans webhook
│   │   ├── Middleware/
│   │   │   ├── JwtAuthenticate.php
│   │   │   ├── CheckPlanFeature.php   # Feature flag gate
│   │   │   ├── CheckPlanLimit.php     # Quota limit check
│   │   │   └── TenantScope.php        # Multi-tenant isolation
│   │   └── Requests/               # Form Request validation
│   ├── Models/                     # Eloquent models
│   ├── Services/
│   │   ├── StandingService.php     # Klasemen engine
│   │   ├── BracketService.php      # Bracket logic
│   │   ├── ScheduleService.php     # Auto-schedule generator
│   │   ├── CertificateService.php  # Nomor + render PDF + simpan ke R2 (satu-satunya)
│   │   ├── SubscriptionService.php # Satu-satunya yang menggeser status langganan
│   │   ├── WalletService.php       # Satu-satunya yang menggeser uang
│   │   ├── MidtransService.php     # Payment wrapper
│   │   ├── R2StorageService.php    # Cloudflare R2 operations
│   │   └── PlanGate.php            # Feature flag & limit paket
│   ├── Jobs/
│   │   ├── SendCertificateJob.php  # Kirim 1 sertifikat; sent_at ditulis setelah terkirim
│   │   ├── ReleaseEventFundsJob.php
│   │   └── RecalculateStandings.php
│   └── Events/ + Listeners/
├── routes/
│   ├── api.php                     # API routes (v1)
│   └── webhook.php                 # Midtrans webhook
├── config/
│   ├── jwt.php
│   ├── r2.php
│   ├── wallet.php                  # Aturan uang (minimum, fee, hold)
│   ├── billing.php                 # Identitas penerbit invoice/kwitansi
│   └── certificate.php             # Prefix nomor, URL verifikasi, katalog field
├── Dockerfile
└── artisan
```

### 6.6 Authentication Flow (JWT RS256)

```
[Next.js: POST /api/auth/login { email, password }]
        ↓
[Laravel: validasi credentials]
[Issue: access_token (15 min) + refresh_token (30 days)]
[Simpan hash refresh_token di DB]
        ↓
Response: { access_token, user }
Set-Cookie: refresh_token=xxx; HttpOnly; Secure; SameSite=Strict

[Frontend]
  • access_token → simpan di Zustand (memory only)
  • refresh_token → otomatis di HttpOnly cookie (tidak bisa diakses JS)

[Setiap API Request]
  Authorization: Bearer <access_token>

[Access token expired (401)]
  → Next.js API route /api/auth/refresh
  → Forward cookie ke Laravel POST /api/auth/refresh
  → Laravel issue access_token baru
  → Frontend update Zustand, retry request

[Logout]
  → DELETE /api/auth/logout
  → Laravel revoke refresh_token di DB
  → Frontend clear Zustand
```

### 6.7 Cloudflare R2 — Struktur Bucket

```
flo-event-storage/
├── organizations/
│   └── {org-id}/logo.webp
├── events/
│   └── {event-id}/
│       ├── banner.webp
│       └── gallery/
├── teams/
│   └── {team-id}/
│       ├── logo.webp
│       └── players/{player-id}/photo.webp
├── documents/
│   └── {registration-id}/{filename}       
├── certificates/
│   ├── {uuid}.webp                        ← background yg diupload organizer
│   └── {certificate-id}.pdf               ← sertifikat terbit (diakses lewat API, bukan URL publik)
└── exports/
    └── {event-id}/
        ├── report-{slug}.pdf
        └── data-{slug}.xlsx
```

**Akses Policy:**
- **Public read:** logo, banner, foto pemain, background template sertifikat
- **Private:** dokumen registrasi (signed URL); **PDF sertifikat** di-stream lewat API (auth + tenant), key bucket-nya tidak pernah keluar ke klien
- **Signed URL upload:** semua upload dari client langsung ke R2 (tidak lewat Laravel, hemat bandwidth VPS)
- **Signed URL expire:** upload = 15 menit, download private = 1 jam

---

## 7. Database Schema

### 7.1 SaaS & Subscription Tables

```sql
-- PLANS
plans (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name              VARCHAR(100) NOT NULL,
  slug              VARCHAR(50) UNIQUE NOT NULL,
  description       TEXT,
  price_monthly     DECIMAL(12,2) DEFAULT 0,
  price_yearly      DECIMAL(12,2) DEFAULT 0,
  is_active         BOOLEAN DEFAULT TRUE,
  is_public         BOOLEAN DEFAULT TRUE,
  sort_order        INT DEFAULT 0,
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- FEATURE DEFINITIONS (master daftar semua feature key)
feature_definitions (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  feature_key       VARCHAR(100) UNIQUE NOT NULL,   -- "max_events", "certificate_email"
  feature_label     VARCHAR(255) NOT NULL,
  feature_group     VARCHAR(100),                   -- "event", "ticket", "certificate"
  feature_type      ENUM('boolean','numeric','text') NOT NULL,
  description       TEXT,
  sort_order        INT DEFAULT 0
)

-- PLAN FEATURES (nilai per paket, dikelola SaaS Admin)
plan_features (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  plan_id           UUID REFERENCES plans(id) ON DELETE CASCADE,
  feature_key       VARCHAR(100) NOT NULL,
  value             TEXT NOT NULL,                  -- "true"/"false" | "10" | "-1" (unlimited)
  UNIQUE(plan_id, feature_key)
)

-- ORGANIZATIONS (Tenant)
organizations (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name              VARCHAR(255) NOT NULL,
  slug              VARCHAR(100) UNIQUE NOT NULL,
  logo_url          TEXT,
  banner_url        TEXT,                           -- header profil publik (§4.19)
  description       TEXT,
  contact_email     VARCHAR(255),
  contact_phone     VARCHAR(20),
  social_links      JSONB,                          -- { "instagram": "https://…", "youtube": null, … }
  -- Satu map JSON, bukan kolom per platform: daftar platform digerakkan kebutuhan
  -- marketing, dan menambah platform berikutnya tidak boleh perlu migrasi.
  -- Sumber kebenaran daftar platform = Organization::SOCIAL_PLATFORMS (mengandung
  -- base URL tiap platform). Form settings, validasi, dan halaman publik membacanya
  -- dari sana — jangan hardcode daftar platform di tempat lain.
  custom_domain     VARCHAR(255),
  owner_id          UUID REFERENCES users(id),
  plan_id           UUID REFERENCES plans(id),
  plan_expires_at   TIMESTAMP,
  storage_used_bytes BIGINT DEFAULT 0,
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- SUBSCRIPTIONS
subscriptions (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id   UUID REFERENCES organizations(id),
  plan_id           UUID REFERENCES plans(id),
  billing_cycle     ENUM('monthly','yearly'),
  amount            DECIMAL(12,2) NOT NULL,
  status            ENUM('active','past_due','cancelled','expired') DEFAULT 'active',
  starts_at         TIMESTAMP NOT NULL,
  expires_at        TIMESTAMP NOT NULL,
  midtrans_order_id VARCHAR(100),
  paid_at           TIMESTAMP,
  created_at        TIMESTAMP DEFAULT NOW()
)
```

### 7.2 Users & Auth Tables

```sql
-- USERS
users (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email             VARCHAR(255) UNIQUE NOT NULL,
  password          VARCHAR(255) NOT NULL,           -- bcrypt
  full_name         VARCHAR(255) NOT NULL,
  phone             VARCHAR(20),
  avatar_url        TEXT,
  role              ENUM('super_admin','user') DEFAULT 'user',
  is_verified       BOOLEAN DEFAULT FALSE,
  email_verified_at TIMESTAMP,
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- REFRESH TOKENS
user_refresh_tokens (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           UUID REFERENCES users(id) ON DELETE CASCADE,
  token_hash        VARCHAR(255) UNIQUE NOT NULL,
  device_info       TEXT,
  ip_address        VARCHAR(45),
  expires_at        TIMESTAMP NOT NULL,
  revoked_at        TIMESTAMP,
  created_at        TIMESTAMP DEFAULT NOW()
)

-- ORGANIZATION MEMBERS
organization_members (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id   UUID REFERENCES organizations(id),
  user_id           UUID REFERENCES users(id),
  role              ENUM('admin','operator'),
  invited_by        UUID REFERENCES users(id),
  created_at        TIMESTAMP DEFAULT NOW(),
  UNIQUE(organization_id, user_id)
)
```

### 7.3 Event & Tournament Tables

```sql
-- EVENTS
events (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id     UUID REFERENCES organizations(id),
  name                VARCHAR(255) NOT NULL,
  slug                VARCHAR(100) NOT NULL,
  sport_type          ENUM('football','futsal','badminton','padel','volleyball'),
  tournament_format   ENUM('league','knockout_single','knockout_double','hybrid'),
  status              ENUM('draft','open','registration_closed','ongoing','finished','cancelled') DEFAULT 'draft',
  start_date          DATE NOT NULL,
  end_date            DATE NOT NULL,
  registration_open   TIMESTAMP,
  registration_close  TIMESTAMP,
  location_name       VARCHAR(255),
  location_address    TEXT,
  description         TEXT,
  banner_url          TEXT,
  max_teams           INT,
  registration_fee    DECIMAL(12,2) DEFAULT 0,
  rules_config        JSONB,                         -- custom scoring rules
  bracket_config      JSONB,
  created_at          TIMESTAMP DEFAULT NOW(),
  updated_at          TIMESTAMP DEFAULT NOW(),
  UNIQUE(organization_id, slug)
)

-- TEAMS
teams (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  name              VARCHAR(255) NOT NULL,
  logo_url          TEXT,
  city              VARCHAR(100),
  jersey_color      VARCHAR(20),
  contact_name      VARCHAR(255),
  contact_phone     VARCHAR(20),
  status            ENUM('pending','approved','rejected','disqualified','withdrawn') DEFAULT 'pending',
  group_name        VARCHAR(10),
  registered_at     TIMESTAMP DEFAULT NOW(),
  approved_at       TIMESTAMP,
  manager_user_id   UUID REFERENCES users(id)
)

-- PLAYERS
players (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  team_id           UUID REFERENCES teams(id),
  full_name         VARCHAR(255) NOT NULL,
  jersey_number     VARCHAR(5),
  position          VARCHAR(50),
  date_of_birth     DATE,
  photo_url         TEXT,
  is_active         BOOLEAN DEFAULT TRUE,
  created_at        TIMESTAMP DEFAULT NOW()
)

-- REGISTRATION DOCUMENTS
registration_documents (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  team_id           UUID REFERENCES teams(id),
  document_type     VARCHAR(100),
  file_url          TEXT NOT NULL,
  file_name         VARCHAR(255),
  file_size_bytes   BIGINT,
  uploaded_at       TIMESTAMP DEFAULT NOW()
)

-- MATCHES
matches (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  team_home_id      UUID REFERENCES teams(id),
  team_away_id      UUID REFERENCES teams(id),
  match_date        TIMESTAMP,
  venue             VARCHAR(255),
  court             VARCHAR(50),
  round             VARCHAR(50),
  match_number      INT,
  group_name        VARCHAR(10),
  bracket_position  INT,
  status            ENUM('scheduled','live','finished','postponed','cancelled') DEFAULT 'scheduled',
  score_home        INT,
  score_away        INT,
  score_detail      JSONB,                           -- set/period scores
  winner_id         UUID REFERENCES teams(id),
  input_by          UUID REFERENCES users(id),
  confirmed_by      UUID REFERENCES users(id),
  confirmed_at      TIMESTAMP,
  notes             TEXT,
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- MATCH EVENTS
match_events (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  match_id          UUID REFERENCES matches(id),
  player_id         UUID REFERENCES players(id),
  team_id           UUID REFERENCES teams(id),
  event_type        ENUM('goal','own_goal','assist','yellow_card','red_card',
                         'penalty_scored','penalty_missed','substitution',
                         'point','ace','smash','block','service_point'),
  minute            INT,
  set_number        INT,
  created_at        TIMESTAMP DEFAULT NOW()
)

-- STANDINGS
standings (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  team_id           UUID REFERENCES teams(id),
  group_name        VARCHAR(10),
  played            INT DEFAULT 0,
  won               INT DEFAULT 0,
  drawn             INT DEFAULT 0,
  lost              INT DEFAULT 0,
  points            INT DEFAULT 0,
  goals_for         INT DEFAULT 0,
  goals_against     INT DEFAULT 0,
  goal_diff         INT DEFAULT 0,
  sets_won          INT DEFAULT 0,
  sets_lost         INT DEFAULT 0,
  extra_data        JSONB,
  updated_at        TIMESTAMP DEFAULT NOW(),
  UNIQUE(event_id, team_id)
)

-- PLAYER STATS
player_stats (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  player_id         UUID REFERENCES players(id),
  team_id           UUID REFERENCES teams(id),
  goals             INT DEFAULT 0,
  assists           INT DEFAULT 0,
  yellow_cards      INT DEFAULT 0,
  red_cards         INT DEFAULT 0,
  matches_played    INT DEFAULT 0,
  minutes_played    INT DEFAULT 0,
  extra_stats       JSONB,
  updated_at        TIMESTAMP DEFAULT NOW(),
  UNIQUE(event_id, player_id)
)

-- Tambahan untuk format Hybrid (grup → playoff) dan adu penalti:
--   matches.stage        → 'group' | 'knockout' (fase pertandingan)
--   matches.penalties_*  → skor adu penalti saat knockout imbang
--   teams.seed_pot       → pot unggulan untuk undian grup

-- EVENT PHOTOS — galeri per event (§4.17)
event_photos (
  id          UUID PK,
  event_id    UUID FK → events,
  album       VARCHAR(100),
  photo_url   TEXT,           -- R2
  caption     TEXT,
  sort_order  INT,
  created_at
)

-- EVENT SPONSORS — logo sponsor bertingkat; daftar tier dari katalog (§4.16)
event_sponsors (
  id          UUID PK,
  event_id    UUID FK → events,
  name        VARCHAR(150),
  logo_url    TEXT,           -- R2
  website_url TEXT,
  tier        VARCHAR(40),    -- platinum | gold | silver | ...
  sort_order  INT,
  created_at
)
```

### 7.4 Ticket & Payment Tables

```sql
-- TICKET CATEGORIES
ticket_categories (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  name              VARCHAR(100) NOT NULL,
  description       TEXT,
  price             DECIMAL(12,2) NOT NULL,
  quota             INT,
  sold              INT DEFAULT 0,
  sale_start        TIMESTAMP,
  sale_end          TIMESTAMP,
  benefits          JSONB,
  is_transferable   BOOLEAN DEFAULT FALSE,
  is_active         BOOLEAN DEFAULT TRUE
)

-- TICKET ORDERS
ticket_orders (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_category_id UUID REFERENCES ticket_categories(id),
  buyer_user_id     UUID REFERENCES users(id),
  buyer_name        VARCHAR(255) NOT NULL,
  buyer_email       VARCHAR(255) NOT NULL,
  buyer_phone       VARCHAR(20),
  quantity          INT DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL,
  total_price       DECIMAL(12,2) NOT NULL,
  platform_fee      DECIMAL(12,2) DEFAULT 0,
  status            ENUM('pending','paid','cancelled','refunded') DEFAULT 'pending',
  midtrans_order_id VARCHAR(100) UNIQUE,
  midtrans_token    TEXT,
  paid_at           TIMESTAMP,
  created_at        TIMESTAMP DEFAULT NOW()
)

-- TICKETS (per tiket individu)
tickets (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id          UUID REFERENCES ticket_orders(id),
  qr_code           VARCHAR(255) UNIQUE NOT NULL,
  holder_name       VARCHAR(255),
  is_used           BOOLEAN DEFAULT FALSE,
  used_at           TIMESTAMP,
  used_by           UUID REFERENCES users(id),
  created_at        TIMESTAMP DEFAULT NOW()
)

-- REGISTRATION PAYMENTS
registration_payments (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  team_id           UUID REFERENCES teams(id),
  amount            DECIMAL(12,2) NOT NULL,
  platform_fee      DECIMAL(12,2) DEFAULT 0,
  status            ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
  midtrans_order_id VARCHAR(100) UNIQUE,
  midtrans_token    TEXT,
  paid_at           TIMESTAMP,
  created_at        TIMESTAMP DEFAULT NOW()
)
```

### 7.5 Wallet & Payout Tables

Uang pembeli ada di akun platform, jadi bagian organizer ditampung di sini sampai ditransfer manual (§4.15).

```sql
-- WALLETS — satu per organisasi. Saldo BOLEH negatif (refund atas dana
-- yang sudah ditarik), dan saldo negatif otomatis mengunci penarikan.
wallets (
  id                 UUID PK,
  organization_id    UUID FK → organizations UNIQUE,
  balance_available  DECIMAL(16,2) DEFAULT 0,  -- siap ditarik
  balance_pending    DECIMAL(16,2) DEFAULT 0,  -- tertahan sampai event selesai
  total_earned       DECIMAL(16,2) DEFAULT 0,
  total_withdrawn    DECIMAL(16,2) DEFAULT 0,
  created_at, updated_at
)

-- WALLET TRANSACTIONS — ledger immutable. Satu baris = satu pergerakan uang.
-- Invarian (dicek perintah audit harian):
--   balance_available = Σ ±amount WHERE status='available'
--   balance_pending   = Σ ±amount WHERE status='pending'
wallet_transactions (
  id              UUID PK,
  wallet_id       UUID FK → wallets,
  organization_id UUID FK → organizations,
  event_id        UUID FK → events NULL,
  type            ENUM('credit','debit'),
  category        ENUM('ticket_sale','registration_fee','refund',
                       'withdrawal','withdrawal_reversal','adjustment'),
  status          ENUM('pending','available','cancelled'),
  amount          DECIMAL(12,2),   -- selalu > 0; `type` yang memberi tanda
  gross_amount    DECIMAL(12,2),   -- yang dibayar pembeli
  fee_amount      DECIMAL(12,2),   -- platform fee / biaya admin penarikan
  source_type     VARCHAR(40),     -- ticket_order | team | withdrawal
  source_id       UUID,
  available_at    TIMESTAMP,       -- kapan boleh cair (akhir hari event, zona WIB)
  released_at     TIMESTAMP,
  created_by      UUID FK → users NULL,
  description     TEXT,
  created_at, updated_at,

  -- Kunci idempotensi: webhook Midtrans yang dikirim ulang tidak akan
  -- mengkredit pesanan yang sama dua kali.
  UNIQUE(source_type, source_id, category)
)

-- BANK ACCOUNTS — rekening tujuan pencairan (satu is_primary per organisasi)
bank_accounts (
  id              UUID PK,
  organization_id UUID FK → organizations,
  bank_name       VARCHAR(100),
  bank_code       VARCHAR(20),
  account_number  VARCHAR(50),
  account_holder  VARCHAR(150),
  is_primary      BOOLEAN DEFAULT TRUE,
  created_at, updated_at
)

-- WITHDRAWALS — permintaan penarikan. Menyimpan SNAPSHOT rekening tujuan dan
-- aturan yang berlaku saat dibuat, jadi mengubah setting/rekening tidak
-- pernah menulis ulang riwayat.
withdrawals (
  id                  UUID PK,
  organization_id     UUID FK → organizations,
  wallet_id           UUID FK → wallets,
  bank_account_id     UUID FK → bank_accounts NULL,
  reference           VARCHAR(30) UNIQUE,        -- WD-XXXXXXXX
  amount              DECIMAL(12,2),             -- yang diterima organizer
  admin_fee           DECIMAL(12,2),
  total_debit         DECIMAL(12,2),             -- amount + admin_fee
  minimum_at_request  DECIMAL(12,2),
  status              ENUM('pending','processing','completed','rejected'),
  bank_name, bank_code, account_number, account_holder,   -- snapshot
  note                TEXT,
  proof_url           TEXT,                      -- bukti transfer (wajib saat selesai)
  transfer_reference  VARCHAR(100),
  admin_note          TEXT,
  requested_by        UUID FK → users NULL,
  processed_by        UUID FK → users NULL,
  processed_at, completed_at, created_at, updated_at
)

-- Backstop DB untuk "maksimal 1 penarikan aktif per organisasi"
CREATE UNIQUE INDEX withdrawals_one_active_per_org ON withdrawals (organization_id)
  WHERE status IN ('pending','processing');
```

---

### 7.6 Catalog & Platform Settings Tables

Vokabuler turnamen dan kebijakan platform yang dikelola Super Admin (§4.16, §3.6).

```sql
-- SPORTS — cabang olahraga (dulu hardcode)
sports (
  id                    UUID PK,
  key                   VARCHAR(20) UNIQUE,   -- football | futsal | badminton | ...
  name                  VARCHAR(50),
  color, icon,
  is_set_based          BOOLEAN,              -- badminton/voli: skor per set
  default_match_minutes INT,
  is_active             BOOLEAN,
  sort_order            INT
)

-- SPORT STATS — kolom statistik pemain per cabang (gol, assist, ace, ...)
sport_stats (
  id        UUID PK,
  sport_id  UUID FK → sports,
  key, label, sort_order
)

-- CONFIG OPTIONS — format turnamen, tiebreaker, metode undian, ronde
-- knockout, tier sponsor. Format = PRESET di atas engine yang ada di kode.
config_options (
  id         UUID PK,
  group      VARCHAR(40),   -- tournament_format | tiebreaker | draw_method | ...
  key        VARCHAR(40),
  label      VARCHAR(100),
  engine     VARCHAR(20),   -- league | knockout | hybrid (hanya untuk format)
  defaults   JSONB,         -- mis. { "legs": 2 } untuk "Liga 2 Putaran"
  is_active  BOOLEAN,
  sort_order INT,
  UNIQUE(group, key)
)

-- PLATFORM SETTINGS — key/value. Default diambil dari config/wallet.php,
-- baris di sini hanya meng-override.
platform_settings (
  id         UUID PK,
  key        VARCHAR(60) UNIQUE,   -- wallet_minimum_withdrawal | wallet_admin_fee | wallet_hold_days
  value      VARCHAR,
  updated_by UUID FK → users NULL,
  created_at, updated_at
)
```

---

### 7.7 Certificate Tables

```sql
-- CERTIFICATE TEMPLATES
-- Milik organisasi, bukan event: satu desain dipakai ulang lintas musim.
certificate_templates (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id   UUID REFERENCES organizations(id) ON DELETE CASCADE,
  name              VARCHAR(255) NOT NULL,
  background_url    TEXT NOT NULL,                  -- R2: certificates/
  orientation       VARCHAR(20) DEFAULT 'landscape',-- landscape|portrait (A4)
  fields            JSONB NOT NULL,
  -- x/y = PERSEN dari background, bukan pixel — layout bertahan di ukuran/DPI apa pun.
  -- x adalah titik yang ditempeli teks (bukan sudut kotak): align=left → teks mulai
  -- di x; right → berakhir di x; center → terpusat di x. Renderer PDF & kanvas editor
  -- memakai rumus yang sama.
  -- fields: [
  --   { "key": "recipient_name", "x": 50, "y": 45, "size": 32,
  --     "color": "#111111", "align": "center", "bold": true, "uppercase": false },
  --   { "key": "award_title",        "x": 50, "y": 35, "size": 16, "align": "center" },
  --   { "key": "certificate_number", "x": 8,  "y": 92, "size": 9,  "align": "left" },
  --   { "key": "qr",                 "x": 85, "y": 70, "size": 15 }   -- sisi = size*3 pt
  -- ]
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- CERTIFICATES (satu baris = satu sertifikat terbit)
-- Nama penerima di-SNAPSHOT, tidak dibaca lewat relasi: tim bisa berganti nama dan
-- pemain bisa dihapus, tapi dokumen yang sudah terbit harus tetap berkata sama.
certificates (
  id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id         UUID REFERENCES organizations(id) ON DELETE CASCADE,
  event_id                UUID REFERENCES events(id) ON DELETE CASCADE,
  certificate_template_id UUID REFERENCES certificate_templates(id) ON DELETE SET NULL,
  certificate_number      VARCHAR(255) UNIQUE NOT NULL,   -- CERT-2026-07-0001
  recipient_type          VARCHAR(10) NOT NULL,           -- team|player
  recipient_id            UUID,                           -- id tim / pemain
  recipient_name          VARCHAR(255) NOT NULL,          -- snapshot
  team_name               VARCHAR(255),                   -- tim si pemain; NULL untuk sertifikat tim
  recipient_email         VARCHAR(255),                   -- email MANAJER tim (tim/pemain tak punya email)
  award_title             VARCHAR(255) NOT NULL,          -- teks bebas: "Juara 1", "Peserta"
  pdf_key                 TEXT,                           -- object key di R2 (bukan URL publik)
  issued_at               TIMESTAMP NOT NULL,
  sent_at                 TIMESTAMP,                      -- diisi hanya setelah email benar-benar terkirim
  created_at              TIMESTAMP DEFAULT NOW(),
  updated_at              TIMESTAMP DEFAULT NOW(),

  -- Satu penghargaan per penerima per event: batch yang dijalankan ulang tidak
  -- boleh menerbitkan "Juara 1" kedua untuk tim yang sama.
  UNIQUE (event_id, recipient_type, recipient_id, award_title)
)
```

> **Template dihapus ≠ sertifikat hilang.** FK-nya `ON DELETE SET NULL` karena PDF-nya sudah ter-render dan tersimpan; template hanya resep, bukan dokumennya.
>
> **`pdf_key`, bukan `pdf_url`.** Sertifikat diunduh lewat API (auth + tenant) yang men-stream objeknya; key bucket tidak pernah keluar ke klien. Tidak ada `png_url` — keluarannya hanya PDF.

### 7.8 Indexes

```sql
CREATE INDEX idx_events_org_id        ON events(organization_id);
CREATE INDEX idx_events_slug          ON events(slug);
CREATE INDEX idx_events_status        ON events(status);
CREATE INDEX idx_teams_event_id       ON teams(event_id);
CREATE INDEX idx_matches_event_id     ON matches(event_id);
CREATE INDEX idx_matches_date         ON matches(match_date);
CREATE INDEX idx_standings_event_id   ON standings(event_id);
CREATE INDEX idx_player_stats_event   ON player_stats(event_id);
CREATE INDEX idx_tickets_qr           ON tickets(qr_code);
CREATE UNIQUE INDEX idx_certs_number  ON certificates(certificate_number);
CREATE INDEX idx_certs_event_type     ON certificates(event_id, recipient_type);
CREATE INDEX idx_plan_features_plan   ON plan_features(plan_id);
CREATE INDEX idx_plan_features_key    ON plan_features(feature_key);
CREATE INDEX idx_refresh_tokens_user  ON user_refresh_tokens(user_id);

-- Wallet & payout
CREATE INDEX idx_wallet_tx_wallet     ON wallet_transactions(wallet_id, created_at);
CREATE INDEX idx_wallet_tx_release    ON wallet_transactions(status, available_at);
CREATE INDEX idx_withdrawals_status   ON withdrawals(status, created_at);
CREATE INDEX idx_withdrawals_org      ON withdrawals(organization_id, status);
CREATE INDEX idx_bank_accounts_org    ON bank_accounts(organization_id);
```

---

## 8. Tech Stack

### 8.1 Frontend (web/)

| Teknologi | Versi | Fungsi |
|-----------|-------|--------|
| **Next.js** | 16 | Framework (App Router, SSR, SSG, Turbopack) |
| **React** | 19 | UI library (wajib untuk Next.js 16) |
| **TypeScript** | 5.1+ | Type safety (minimum Next.js 16) |
| **Tailwind CSS** | 4 | Utility-first styling (CSS-first config) |
| **shadcn/ui** | latest | Component library (Radix UI) |
| **Axios** | 1+ | HTTP client + JWT interceptor |
| **TanStack Query** | 5+ | Data fetching & caching |
| **Zustand** | 5+ | Global state (auth, UI) |
| **React Hook Form** | 7+ | Form management |
| **Zod** | 4+ | Schema validation |
| **Recharts** | 3+ | Chart & statistik |
| **react-bracket** | latest | Tournament bracket visualisasi |
| **QRCode.react** | latest | Generate QR Code |
| **html5-qrcode** | latest | QR scanner via kamera |
| **jsPDF** | 3+ | Export PDF client-side |
| **SheetJS (xlsx)** | latest | Export Excel client-side |
| **Motion** | 12+ | Animasi (sebelumnya Framer Motion) |
| **date-fns** | 4+ | Date manipulation |

> **Catatan:** Konva.js / canvas editor dihapus. Certificate positioning menggunakan UI sederhana (input X/Y + preview render dari server).

### 8.2 Backend (api/)

| Teknologi | Versi | Fungsi |
|-----------|-------|--------|
| **Laravel** | 13 | PHP framework |
| **PHP** | 8.4 (min 8.3) | Runtime |
| **tymon/jwt-auth** | 2+ | JWT RS256 authentication |
| **Laravel Horizon** | 6+ | Queue monitoring |
| **Laravel Telescope** | 5+ | Debugging (dev only) |
| **Spatie Permission** | 6+ | Role & permission |
| **barryvdh/laravel-dompdf** | 3+ | Render PDF: invoice, kwitansi, **sertifikat** |
| **maatwebsite/excel** | 3+ | Export Excel |
| **intervention/image** | 4+ | Re-encode gambar (upload → WebP; background sertifikat → JPEG) |
| **bacon/bacon-qr-code** | 3+ | QR verifikasi sertifikat (SVG, digambar dompdf sebagai vektor) |
| **aws/aws-sdk-php** | 3+ | Cloudflare R2 (S3-compatible SDK) |
| **league/flysystem-aws-s3-v3** | 3+ | Adapter disk `r2` (wajib; tanpa ini `Storage::disk('r2')` fatal) |
| **GuzzleHTTP** | 7+ | HTTP client (Midtrans) |
| **Laravel Pint** | — | Code formatter |
| **Pest PHP** | 4+ | Testing framework (browser testing) |

### 8.3 Infrastructure & DevOps

| Komponen | Tool |
|----------|------|
| **Containerization** | Docker + Docker Compose |
| **Reverse Proxy** | Nginx |
| **Database** | PostgreSQL 17 (container di VPS) |
| **Cache & Queue** | Redis 8 (container di VPS) |
| **File Storage** | Cloudflare R2 |
| **SSL** | Let's Encrypt via Certbot |
| **CI/CD** | GitHub Actions |
| **Error Monitoring** | Sentry |
| **Log Management** | Laravel Telescope (dev), file logs (prod) |
| **DB Backup** | Spatie Laravel Backup → upload ke R2 |

### 8.4 Authentication

| Komponen | Detail |
|----------|--------|
| **Algoritma** | RS256 (RSA asymmetric) |
| **Access Token TTL** | 15 menit |
| **Refresh Token TTL** | 30 hari |
| **Access Token Storage** | In-memory (Zustand, hilang saat tab tutup) |
| **Refresh Token Storage** | HttpOnly Secure Cookie (tidak bisa diakses JS) |
| **Refresh Endpoint** | `POST /api/v1/auth/refresh` |
| **Revocation** | Hash refresh token disimpan di DB, dapat di-revoke |

### 8.5 External Services

| Service | Fungsi |
|---------|--------|
| **Midtrans** | Payment gateway — subscription, registrasi, tiket |
| **Resend** | Transactional email — konfirmasi, notifikasi, sertifikat |
| **Cloudflare R2** | File storage — semua aset & file generate |
| **Cloudflare CDN** | Serve aset publik (logo, banner, foto) |
| **Sentry** | Error monitoring frontend & backend |

### 8.6 Tech Stack Diagram

```
┌────────────────────────────────────────────────────────────┐
│              FRONTEND — Next.js 16 (web/)                  │
│  TypeScript | Tailwind 4 | shadcn/ui | TanStack Query       │
│  Zustand | React Hook Form | Zod                            │
└───────────────────────┬────────────────────────────────────┘
                        │ REST API + JWT RS256
┌───────────────────────▼────────────────────────────────────┐
│              BACKEND — Laravel 13 (api/)                    │
│  PHP 8.4 | Eloquent | Horizon | Pest                        │
│  tymon/jwt | DomPDF | Intervention Image | Maatwebsite Excel│
└──────┬───────────────┬────────────────┬────────────────────┘
       │               │                │
       ▼               ▼                ▼
┌────────────┐  ┌────────────┐  ┌──────────────────┐
│ PostgreSQL │  │   Redis    │  │  Cloudflare R2   │
│ (container)│  │ (container)│  │  (file storage)  │
└────────────┘  └────────────┘  └──────────────────┘
                                        │
                               ┌────────▼──────────┐
                               │  Cloudflare CDN   │
                               │  (serve public    │
                               │   assets)         │
                               └───────────────────┘
       │
┌──────▼─────────────────────────────────────────────────────┐
│                   EXTERNAL SERVICES                         │
│           Midtrans | Resend | Sentry                        │
└────────────────────────────────────────────────────────────┘
         ▲─────── semua di dalam VPS via Docker Compose ──────▲
```

---

## 9. Design Guidelines

### 9.1 Brand Identity

**Nama:** flo-event
**Tagline:** *Atur Turnamen, Tanpa Batas.*

**Prinsip Desain:**
- **Clarity first** — informasi penting selalu terlihat, tanpa noise
- **Consistent** — pola UI yang sama di seluruh halaman
- **Accessible** — kontras warna WCAG 2.1 AA
- **Performant** — minimal animasi berat, prioritas kecepatan
- **Dark mode ready** — semua komponen support light & dark

---

### 9.2 Color System

```css
/* PRIMARY — biru tua */
--brand-600:    #0C54D0;   /* CTA, link, primary action */
--brand-700:    #0944A6;   /* Hover */
--brand-500:    #3A72E0;   /* Light accent */
--brand-100:    #E7EFFC;   /* Tint, selected background */

/* NEUTRAL */
--gray-950:     #0A0E1A;   /* Dark mode page bg */
--gray-900:     #111827;   /* Dark mode card bg */
--gray-800:     #1F2937;   /* Dark mode border */
--gray-700:     #374151;   /* Secondary text */
--gray-500:     #6B7280;   /* Muted, placeholder */
--gray-300:     #D1D5DB;   /* Light mode border */
--gray-100:     #F3F4F6;   /* Light bg, alternate section */
--gray-50:      #F9FAFB;   /* Table row hover */
--white:        #FFFFFF;   /* Card bg light mode */

/* STATUS */
--success:      #16A34A;   --success-bg: #DCFCE7;
--warning:      #D97706;   --warning-bg: #FEF3C7;
--danger:       #DC2626;   --danger-bg:  #FEE2E2;
--info:         #2563EB;   --info-bg:    #DBEAFE;

/* SPORT ACCENTS */
--sport-football:   #0C54D0;
--sport-futsal:     #7C3AED;
--sport-badminton:  #DB2777;
--sport-padel:      #D97706;
--sport-volleyball: #059669;

/* PLAN COLORS */
--plan-free:        #6B7280;
--plan-starter:     #2563EB;
--plan-pro:         #7C3AED;
--plan-professional:#D97706;  /* Gold */
```

---

### 9.3 Typography

```
Font:
  UI Body:   Inter (400, 500, 600, 700)
  Display:   Outfit (600, 700, 800)
  Mono:      JetBrains Mono (400, 500)  — cert number, QR ref, code

Scale:
  xs    12px  caption, badge
  sm    14px  secondary text, table cell
  base  16px  body default
  lg    18px  card title
  xl    20px  sub-heading
  2xl   24px  heading
  3xl   30px  page title
  4xl   36px  section hero
  5xl   48px  landing hero
```

---

### 9.4 Spacing & Layout

```
Base unit: 4px
Container max-width: 1280px | padding: 24px (mobile) → 48px (≥lg)
Grid: 12 column | gap: 16px (mobile) → 24px (desktop)

Dashboard Sidebar:
  Expanded:  260px
  Collapsed: 72px
  Mobile:    Bottom tab bar (5 item max)

Card:
  Padding:       24px
  Border radius: 12px (rounded-xl)
  Border:        1px solid gray-200 / gray-800 (dark)
  Shadow:        0 1px 3px rgba(0,0,0,0.08)
```

---

### 9.5 Component Guidelines

**Buttons:**

| Variant | Style |
|---------|-------|
| Primary | brand-600 bg, white text |
| Secondary | white bg, brand-600 border + text |
| Ghost | transparent, gray-700 text |
| Danger | red-600 bg, white text |

Size: sm (32px h), md (40px h), lg (48px h). Selalu ada loading state dan disabled state.

**Form Input:** height 40px, border 1px gray-300, focus border brand-600, error border red-500, label semibold di atas input.

**Klasemen Table:** header gray-50 + semibold uppercase; posisi lolos = border-l-4 success; playoff = border-l-4 warning; tersingkir = border-l-4 danger.

**Status Badge:** rounded-full, warna sesuai status (pending=warning, confirmed=success, live=red + pulse animation).

---

### 9.6 Certificate Positioner UI

Halaman setup template sertifikat menggunakan pendekatan **overlay preview** (`components/certificate/template-editor.tsx`):

```
┌──────────────────────────────────────────────────────┐
│  KIRI: Preview Canvas (background + elemen overlay)  │
│  ┌────────────────────────────────────────────────┐  │
│  │   [Background sertifikat yang diupload]        │  │
│  │                                                 │  │
│  │   ┌──────────────────────┐  ← draggable box    │  │
│  │   │  Nama Penerima       │                      │  │
│  │   └──────────────────────┘                      │  │
│  │                                                 │  │
│  └────────────────────────────────────────────────┘  │
│                                                       │
│  KANAN: Panel Properti Field yang Dipilih             │
│  ┌─────────────────────────────────────────────────┐  │
│  │  Field: Nama Penerima                            │  │
│  │  X: [50] %  Y: [45] %                            │  │
│  │  Font size: [32] pt   Align: [Tengah ▼]          │  │
│  │  Color: [#111111]   ☑ Tebal   ☐ HURUF KAPITAL    │  │
│  └─────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

- Drag field di canvas → update nilai X/Y di panel kanan
- Input X/Y manual di panel → pindah posisi field di canvas
- Dropdown "Tambah field" hanya menampilkan field yang **belum dipakai**; opsinya ditarik dari `GET /certificate-fields` (katalog `config/certificate.php`), tidak dihardcode di UI
- Field menampilkan **contoh teks** ("Garuda FC", "Juara 1"), bukan `{{placeholder}}`
- Simpan → POST ke API → disimpan di kolom `fields` (JSONB)

**Kanvas wajib memakai geometri yang sama dengan renderer PDF.** Koordinat disimpan dalam persen, ukuran font dalam **pt** diskalakan ke lebar halaman A4 di layar (`px = pt × lebarKanvas / 842`), dan alignment menentukan sisi teks mana yang menempel di X. Kalau editor dan PDF memakai rumus berbeda, hasil cetak akan meleset dari yang disusun organizer — dan itu baru ketahuan setelah ratusan sertifikat tercetak.

> Tidak ada tombol "Preview dengan data asli" ke server: kanvas **adalah** previewnya. Menambah render-preview di server berarti dua implementasi tata letak yang harus dijaga tetap sinkron.

---

### 9.7 Responsive Breakpoints

```
sm:   < 640px   (mobile)
md:   640-768px
lg:   768-1024px
xl:   1024-1280px
2xl:  > 1280px

Mobile-first. Dashboard: sidebar → bottom nav di < lg.
Bracket: horizontal scroll di < md.
Klasemen: sticky kolom nama tim di < md.
```

---

## 10. Development Process Flow

### 10.1 Development Phases

#### Phase 0 — Foundation & Docker Setup 

```
Monorepo & Docker:
  ✅ Init struktur flo-event/ (web/, api/)
  ✅ Tulis docker-compose.yml (nginx, web, api, worker, db, redis)
  ✅ Tulis docker-compose.dev.yml (hot reload, volume mount)
  ✅ Konfigurasi Nginx reverse proxy
  ✅ Setup Let's Encrypt SSL (Certbot)
  ✅ GitHub repository + branch protection

Backend (api/):
  ✅ Init Laravel 13 + PHP 8.4
  ✅ Setup tymon/jwt-auth + generate RS256 key pair
  ✅ Setup PostgreSQL connection + core migrations
  ✅ Setup Redis + Laravel Horizon
  ✅ Setup Cloudflare R2 (aws-sdk + R2StorageService)
  ✅ Base API response format (success/error wrapper)
  ✅ Setup Pest testing

Frontend (web/):
  ✅ Init Next.js 16 + TypeScript + Tailwind 4
  ✅ Setup shadcn/ui + design tokens
  ✅ Setup Axios + JWT interceptor (auto-refresh via cookie)
  ✅ Setup TanStack Query + Zustand auth store
  ✅ Base layout: public, dashboard, auth
  ✅ Setup Sentry
```

#### Phase 1 — Auth & Subscription 

```
api/:
  → Auth: register, login, logout, refresh, verify email
  → SaaS Admin: CRUD plans + plan_features + feature_definitions
  → Organization onboarding + plan assignment
  → Middleware: CheckPlanFeature, CheckPlanLimit, TenantScope
  → Subscription billing (Midtrans + webhook)

web/:
  → Halaman register, login, forgot/reset password
  → Halaman pricing publik
  → Dashboard welcome + pilih paket
  → Payment flow subscription
  → Panel SaaS Super Admin: kelola paket & fitur
```

#### Phase 2 — Core Tournament

```
Sprint 2A (Minggu 5–6) — Event & Registrasi:
  api/:
    → Event CRUD
    → Team & Player CRUD
    → R2 signed URL untuk upload (logo, foto, dokumen)
    → Approval workflow
    → Registration payment (Midtrans)
  web/:
    → Buat & edit event
    → Landing page event (publik)
    → Form registrasi tim + upload
    → Dashboard tim (peserta)
    → Admin: kelola pendaftaran

Sprint 2B (Minggu 7–8) — Jadwal, Hasil & Klasemen:
  api/:
    → ScheduleService: round-robin + knockout generator
    → Match CRUD + result input
    → StandingService: kalkulasi klasemen
    → Player stats aggregation
    → Konfirmasi workflow
  web/:
    → Jadwal (list + kalender)
    → Form input hasil per cabang
    → Klasemen real-time
    → Statistik + leaderboard
    → Bracket visualisasi
```

#### Phase 3 — Tiket & Payment (Minggu 9–10)

```
api/:
  → Ticket category CRUD
  → Ticket purchase + QR code generation (UUID encrypted)
  → QR scan validation (one-time use, server-side)
  → Check-in report
  → Midtrans webhook (tiket + registrasi)

web/:
  → Manajemen kategori tiket
  → Halaman beli tiket (publik)
  → E-tiket dengan QR Code
  → Halaman scanner check-in (kamera)
  → Dashboard keuangan & check-in report
```

#### Phase 3B — Dompet & Penarikan Dana

Konsekuensi dari keputusan bahwa **semua uang mendarat di akun Midtrans platform** (§4.12): tanpa fase ini, dana organizer tidak punya jalan keluar.

```
api/:
  → wallets + wallet_transactions (ledger immutable, idempoten per sumber)
  → Kredit neto saat pembayaran lunas; dana tertahan sampai event selesai
  → Perintah terjadwal: rilis dana (per jam) + audit saldo vs ledger (harian)
  → Rekening bank + permintaan penarikan (min, biaya admin, 1 request aktif)
  → Antrian pencairan Super Admin: proses / selesai (+bukti) / tolak
  → Refund Super Admin → koreksi saldo organizer
  → Middleware org.admin: endpoint uang tertutup untuk member `operator`
  → platform_settings: aturan pencairan diubah tanpa deploy
  → wallet:backfill untuk pembayaran lunas yang sudah ada

web/:
  → /organizer/wallet — saldo, mutasi, rekening, dialog tarik dana
  → /admin/withdrawals — antrian pencairan + upload bukti transfer
  → /admin/payments — pembayaran platform + refund
  → /admin/settings — aturan pencairan
```

Detail: **`WALLET.md`**.

#### Phase 4 — Generator Sertifikat (Minggu 11–12) — ✅ selesai

```
api/:
  → Certificate template CRUD (simpan fields JSONB, koordinat persen)
  → Upload background lewat endpoint upload gambar yang sudah ada (R2)
  → CertificateService (sinkron, bukan queue):
      → Ambil background dari bucket sekali per batch → re-encode ke JPEG
      → dompdf: render teks + QR vektor di atas background → PDF A4
      → Upload ke R2: certificates/{certificate-id}.pdf
  → Nomor sertifikat: CERT-YYYY-MM-NNNN, sequence dikunci, unique index
  → SendCertificateJob (Queue, paket Pro+) — sent_at ditulis setelah terkirim
  → Verifikasi sertifikat endpoint (publik)
  → Download PDF lewat API (stream), bukan URL bucket

web/:
  → Upload background interface
  → Certificate positioner (drag field + input X/Y + panel properti)
  → Generate UI (pilih event, template, penghargaan, penerima; "Pilih semua")
  → Download PDF per sertifikat / kirim via email
  → Halaman verifikasi sertifikat publik
```

**Yang berubah dari rencana awal, beserta alasannya:**

| Rencana | Yang dibangun | Alasan |
|---------|---------------|--------|
| Generate lewat Queue job | **Sinkron** di request | Batch normal (puluhan) selesai dalam detik; queue menambah status "sedang diproses" yang harus di-poll UI tanpa manfaat nyata. Yang di-queue justru **email**, karena SMTP-lah yang lambat & bisa gagal. |
| Preview endpoint di server | **Kanvas editor = preview** | Dua implementasi tata letak (server & klien) pasti akan berbeda diam-diam. Satu rumus, dipakai keduanya. |
| Overlay via Intervention Image | **dompdf** | Butuh teks tajam & QR vektor yang tidak pecah saat dicetak; raster tidak memenuhi itu. |
| Output PDF **+ PNG** | **PDF saja** | Tidak ada alur yang memakai PNG-nya. |
| Download **ZIP** semua | Download **per sertifikat** | ZIP ratusan file berarti kerja batch + storage sementara; belum ada bukti dibutuhkan. Bisa ditambahkan nanti kalau organizer memintanya. |
| Field `logo_event` & `signature` | **Tidak ada** | Keduanya sudah menyatu di artwork yang diunggah organizer — itu inti dari "bawa desainmu sendiri". |

#### Phase 5 — Export & Laporan (Minggu 13–14)

```
api/:
  → Excel export (maatwebsite/excel): jadwal, klasemen, statistik, peserta
  → PDF laporan turnamen (DomPDF + Blade template)
  → Upload hasil export ke R2 (temp, expire 24 jam)

web/:
  → Export buttons di setiap modul
  → Halaman laporan turnamen
  → Embed bracket sharable link
  → Print-friendly CSS
```

#### Phase 6 — QA, Optimasi & Launch (Minggu 15–16)

```
QA:
  → E2E testing Playwright (10 critical flows)
  → Load test (k6): concurrent check-in, result input
  → Security audit: JWT, SQL injection, OWASP Top 10
  → Mobile responsiveness review (360px–1440px)
  → Dark mode review

Optimasi:
  → Image: convert ke WebP, lazy load
  → Redis caching untuk klasemen & standings
  → DB query audit (EXPLAIN ANALYZE)
  → Docker image size optimization (multi-stage build)

Launch:
  → Soft launch (beta terbatas)
  → Bug fix iteration
  → Public launch
  → Monitoring alerts setup (Sentry + Uptime Robot)
  → Dokumentasi API (L5-Swagger / Scribe)
```

---

### 10.2 API Design Convention

**Base URL:** `https://api.flo-event.id/v1`

**Response Format:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Success",
  "meta": { "page": 1, "per_page": 15, "total": 120, "last_page": 8 }
}
```

**Error Format:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": { "email": ["Email sudah terdaftar"] },
  "code": 422
}
```

**Endpoint Naming (RESTful):**

Endpoint milik tenant selalu bersarang di bawah organisasi dan melewati middleware `tenant`
(+ `org.admin` untuk endpoint uang/langganan):
```
GET    /v1/organizations/{org}/events
POST   /v1/organizations/{org}/events
POST   /v1/organizations/{org}/events/{event}/publish        ← custom action

GET    /v1/organizations/{org}/certificate-fields            ← katalog field (untuk editor)
GET    /v1/organizations/{org}/certificate-templates
POST   /v1/organizations/{org}/certificate-templates
PATCH  /v1/organizations/{org}/certificate-templates/{id}
DELETE /v1/organizations/{org}/certificate-templates/{id}

GET    /v1/organizations/{org}/certificates                  ← ?event_id= (opsional)
GET    /v1/organizations/{org}/events/{event}/certificate-recipients
POST   /v1/organizations/{org}/events/{event}/certificates   ← terbitkan batch
GET    /v1/organizations/{org}/certificates/{id}/download    ← stream PDF
POST   /v1/organizations/{org}/certificates/{id}/send        ← queue email
DELETE /v1/organizations/{org}/certificates/{id}
```

Endpoint publik (tanpa auth):
```
GET    /v1/public/events                       ← katalog: ?search=&sport=&status=&org=&page=
GET    /v1/public/events/{orgSlug}/{eventSlug} ← landing page event
GET    /v1/public/organizations/{orgSlug}      ← profil penyelenggara
GET    /v1/public/certificates/{number}        ← verifikasi sertifikat (tujuan QR)
```

**Pelanggaran batas paket dijawab `403` dengan `errors.feature`** (mis. `{"feature": "certificate_email"}`), supaya klien bisa membedakannya dari 403 biasa dan mengarahkan user ke halaman upgrade.

---

### 10.3 Git Workflow

```
main            ← production
  │
  ├── develop   ← integration
  │     │
  │     ├── feature/{scope}-{desc}   → feature/cert-positioner
  │     ├── fix/{scope}-{desc}       → fix/standings-tiebreaker
  │     └── chore/{desc}             → chore/upgrade-php-84
```

**Commit Convention:**
```
feat(cert): add background upload + field positioner
fix(standings): correct voli point calculation
feat(auth): implement JWT RS256 refresh rotation
chore(docker): add multi-stage build for api image
test(ticket): add QR one-time use validation tests
```

---

### 10.4 CI/CD Pipeline

```
Push ke feature/* / fix/*:
  ├── api/: composer install, Laravel Pint, Pest
  ├── web/: pnpm install, ESLint, TypeScript check, Vitest
  └── Docker build test (pastikan image build sukses)

Merge ke develop:
  ├── Full test suite
  ├── E2E tests subset (Playwright)
  └── Deploy ke staging (docker-compose pull + up di staging VPS)

Merge ke main:
  ├── Full E2E
  ├── Security scan (Snyk)
  ├── Deploy ke production VPS via SSH + Docker Compose
  ├── Health check post-deploy
  └── Rollback otomatis jika health check gagal
```

---

### 10.5 Testing Strategy

| Layer | Tool | Target |
|-------|------|--------|
| Unit BE | Pest PHP | 75% services & business logic |
| Feature BE | Pest + Laravel TestCase | Semua endpoint API |
| Unit FE | Vitest | Utils, hooks, stores |
| Component FE | Vitest + Testing Library | Core UI components |
| E2E | Playwright | 10 critical flows |
| Load | k6 | Concurrent check-in, result input |
| Security | Manual OWASP | Pre-launch |

**10 Critical E2E Flows:**
1. Organizer onboarding → pilih paket → bayar subscription
2. Buat event → publish → landing page tampil publik
3. Peserta registrasi tim → bayar → approval → dashboard tim aktif
4. Generate jadwal → input hasil → klasemen auto-update
5. Beli tiket → terima QR → scan check-in valid
6. Upload background sertifikat → atur posisi → terbitkan batch
7. Download PDF sertifikat → scan QR → halaman verifikasi menampilkan dokumen yang sah
8. Export laporan PDF & Excel
9. JWT expired → refresh token → request berhasil
10. SaaS admin ubah plan feature → langsung berlaku untuk tenant

---

### 10.6 Definition of Done

- [ ] Acceptance criteria terpenuhi
- [ ] Unit & feature tests written + passing
- [ ] Tidak ada TypeScript error / PHP strict type error
- [ ] Code review diapprove minimal 1 reviewer
- [ ] Responsive di mobile (≥360px) & desktop
- [ ] Dark mode berfungsi
- [ ] Loading, empty state, error state ditangani
- [ ] Feature flag middleware diterapkan (jika fitur berbayar)
- [ ] Docker build sukses tanpa error
- [ ] Deployed ke staging & diverifikasi

---

### 10.7 Feature Priority Matrix

| Fitur | Impact | Effort | Phase | Priority |
|-------|--------|--------|-------|----------|
| Docker setup + monorepo | High | Medium | 0 | P0 |
| Auth + JWT RS256 | High | Medium | 1 | P0 |
| Manajemen Paket SaaS Admin | High | Medium | 1 | P0 |
| Subscription + Billing | High | High | 1 | P0 |
| Buat Event + Landing Page | High | Medium | 2 | P0 |
| Registrasi Tim + Upload R2 | High | Medium | 2 | P0 |
| Jadwal + Input Hasil | High | Medium | 2 | P0 |
| Klasemen Real-time | High | Medium | 2 | P0 |
| Bracket Turnamen | High | Medium | 2 | P0 |
| Dashboard Admin | High | High | 2 | P0 |
| Tiket QR + Check-in | High | High | 3 | P1 |
| Generator Sertifikat | Medium | High | 4 | P1 |
| Statistik Pemain | Medium | Medium | 2 | P1 |
| Export Excel / PDF | Medium | Medium | 5 | P1 |
| Email Notifikasi | Medium | Low | 3 | P2 |
| Kirim Sertifikat Email | Medium | Medium | 4 | P2 |
| Laporan Turnamen | Medium | Medium | 5 | P2 |
| Custom Domain | Low | Medium | Post-MVP | P3 |
| White Label | Low | High | Post-MVP | P3 |

---

## Appendix

### A. Glossary

| Term | Definisi |
|------|----------|
| **Tenant** | Satu organisasi dalam sistem multi-tenant |
| **Feature Flag** | Toggle fitur dikontrol berdasarkan paket |
| **fields** (template) | JSONB berisi posisi (persen) & style setiap field sertifikat |
| **Certificate Background** | File gambar (JPG/PNG) yang diupload organizer sebagai template sertifikat |
| **Overlay** | Proses mencetak teks/QR di atas background saat merender PDF sertifikat |
| **Signed URL** | URL sementara untuk akses/upload file R2 secara langsung |
| **Horizon** | Dashboard monitoring queue Laravel |
| **TenantScope** | Middleware yang memastikan query selalu di-filter per `organization_id` |

### B. Certificate Number Format

```
Format:  {PREFIX}-{YEAR}-{MONTH}-{4DIGIT}     -- prefix dari config/certificate.php
Contoh:  CERT-2026-07-0001

Verifikasi publik:  flo-event.id/verify/CERT-2026-07-0001
```

- Urutan **reset tiap bulan**, sama seperti penomoran invoice (`INV/2026/07/0001`).
- Dipisah **tanda hubung**, bukan garis miring seperti invoice: nomor ini menjadi segmen URL verifikasi dan nama file PDF, jadi slash akan merusak keduanya.
- Nomor dicetak sekali saat terbit dan dijamin unik oleh unique index — sequence diambil dengan mengunci baris tertinggi (`ORDER BY … DESC … FOR UPDATE`), **bukan** mengunci `max()`: Postgres menolak `FOR UPDATE` bersama fungsi agregat.

### C. VPS Recommended Spec

| Komponen | Minimum | Recommended |
|----------|---------|-------------|
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB | 8 GB |
| Storage | 50 GB SSD | 100 GB SSD |
| Bandwidth | 1 TB/bulan | 2 TB/bulan |
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |

> File besar (gambar, sertifikat, export) disimpan di Cloudflare R2, bukan di VPS, sehingga storage VPS hanya untuk OS, Docker, logs, dan database.

### D. Future Roadmap

| Timeline | Fitur |
|----------|-------|
| Q3 2026 | Pencairan **otomatis** via Midtrans Iris (menggantikan transfer manual §4.15) |
| Q3 2026 | Sinkronisasi refund dari dashboard Midtrans ke dompet (webhook `refund` / `partial_refund`) |
| Q3 2026 | Live scoring WebSocket |
| Q4 2026 | Tambahan cabang olahraga (basket, tenis) |
| Q1 2027 | Mobile app (React Native) |
| Q2 2027 | Streaming embed (YouTube/FB Live) |
| Q3 2027 | White label + custom domain (Professional) |
| Q4 2027 | Marketplace event discovery publik |
| 2028 | Multi-VPS / horizontal scaling |

### E. Risk Register

| Risiko | Prob. | Dampak | Mitigasi |
|--------|-------|--------|---------|
| VPS down | Low | Critical | Monitoring + auto-restart Docker, backup R2 |
| Midtrans downtime | Medium | High | Retry queue, notifikasi manual |
| DB corrupt | Very Low | Critical | Spatie Backup harian ke R2 |
| R2 outage | Very Low | Medium | Cloudflare SLA 99.9%, cached CDN URL |
| JWT key compromise | Very Low | Critical | Key rotation policy, stored di .env terenkripsi |
| Queue job failure | Medium | Medium | Laravel Horizon retry, failed job table |
| Scope creep | High | Medium | Strict phase planning, backlog grooming |
| Refund setelah dana ditarik organizer | Medium | High | Dana ditahan sampai event selesai; saldo boleh minus & mengunci penarikan; opsi masa tahan tambahan (§3.6) |
| Saldo dompet melenceng dari ledger | Low | Critical | Semua mutasi lewat satu service + row lock; unique index per sumber; audit harian `wallet:audit` |
| Operator organisasi menyalahgunakan dompet | Medium | Critical | Endpoint uang wajib `org.admin` — member `operator` (petugas scan) ditolak |
| Refund Midtrans tidak sinkron dengan dompet | Medium | High | Prosedur: refund selalu lewat panel admin; webhook refund masuk roadmap Q3 2026 |

---

*Dokumen ini adalah living document yang diperbarui setiap sprint.*

**Version:** 1.1.0
**Last Updated:** Juli 2026
**Next Review:** Agustus 2026
**Owner:** Product Team flo-event

**Changelog v1.1.0** — Hybrid format (grup → playoff, undian pot, adu penalti) & media event; katalog cabang olahraga / format / statistik dipindah ke database dan dikelola Super Admin; dompet organizer & penarikan dana (FR-17), pengaturan platform (FR-18), galeri & sponsor (FR-19).
