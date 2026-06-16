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
- Atur posisi elemen di atas background: nama peserta, nama tim, penghargaan, logo event, tanda tangan
- Preview hasil sebelum generate
- Generate batch PDF + PNG per penerima via background job
- Output disimpan di R2, dapat didownload atau dikirim via email

#### FR-13: Export Excel / PDF
- Export jadwal, klasemen, statistik, data peserta
- Laporan keuangan dan laporan turnamen komprehensif

#### FR-14: Payment Gateway
- Integrasi Midtrans untuk semua transaksi
- Dashboard keuangan per event dan refund management

#### FR-15: Tiket QR Code + Scan Check-in
- Tiket digital dengan QR Code unik per tiket
- Halaman scan berbasis kamera (no app install)
- Validasi server-side one-time use

#### FR-16: Laporan Turnamen
- Laporan end-of-tournament: klasemen akhir, statistik, ringkasan keuangan
- Export PDF & Excel, share link online

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

**Alur Setup Template:**
1. Organizer upload file background sertifikat (JPG/PNG, resolusi tinggi) ke R2
2. Sistem menampilkan preview background di layar
3. Organizer menentukan **posisi** setiap elemen dengan menggeser atau input koordinat X/Y:
   - Nama penerima (tim atau pemain)
   - Nama tim
   - Jenis penghargaan (Juara 1, Top Scorer, dll.)
   - Nama event & tanggal
   - Logo event (opsional, jika ingin di-overlay)
   - Tanda tangan digital (gambar, opsional)
4. Organizer mengatur: ukuran font, warna teks, alignment per field
5. Klik **Preview** → sistem render contoh sertifikat dengan data dummy
6. Simpan konfigurasi posisi sebagai template

**Alur Generate Sertifikat:**
```
[Event Selesai]
  → Dashboard → Modul Sertifikat
  → Pilih template yang sudah dikonfigurasi
  → Pilih jenis: Juara Tim / Penghargaan Individu / Semua
  → Klik "Generate Semua"
  → [Background Job: Laravel Queue]
      → Ambil background dari R2
      → Overlay teks + logo per penerima (Intervention Image / GD)
      → Generate PDF A4 landscape per sertifikat
      → Upload ke R2: certificates/[event-id]/[cert-id].pdf
  → Notifikasi: "X sertifikat siap didownload"
  → Download ZIP semua sertifikat
  → Atau "Kirim via Email" (paket Pro ke atas)
```

**Jenis Sertifikat yang Di-generate:**
- Juara 1, 2, 3 (tim)
- Juara Harapan (opsional)
- Top Scorer, Top Assist, MVP
- Penghargaan custom (dikonfigurasi organizer)

**Data Auto-populate:**
- Nama penerima (tim atau pemain)
- Nama event & tanggal pelaksanaan
- Jenis penghargaan
- Nomor sertifikat unik: `COT-2026-00001`
- QR Code verifikasi → `flo-event.id/verify/[cert-id]`

---

### 4.11 Export Excel / PDF

**Excel (.xlsx):** data registrasi, jadwal, klasemen, statistik pemain, laporan keuangan

**PDF:** laporan turnamen resmi, bracket akhir, invoice, daftar hadir

---

### 4.12 Payment Gateway (Midtrans)

**Metode:** Virtual Account (BCA/BNI/BRI/Mandiri), QRIS, GoPay/OVO/DANA/ShopeePay, Kartu Kredit/Debit

**Transaksi:** biaya subscription SaaS, biaya registrasi tim, pembelian tiket penonton

**Platform fee** dipotong otomatis dari setiap transaksi sesuai paket organizer.

---

### 4.13 Tiket QR Code + Scan Check-in

**Pembelian:** pilih kategori tiket → isi data → bayar → terima e-tiket via email (QR Code unik)

**Check-in:** operator buka `flo-event.id/scan/[event-id]` → scan QR → validasi server-side → ✅ Valid / ❌ Sudah digunakan

---

### 4.14 Laporan Turnamen

Konten: ringkasan event, klasemen akhir, bracket hasil, top statistik, rekap keuangan

Output: PDF siap cetak, Excel arsip, share link online

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
[Dashboard] → Modul Sertifikat
  → "Buat Template Baru"
      → Upload file background sertifikat (JPG/PNG)
      → Atur posisi setiap field (drag atau input X/Y):
          • Nama penerima, nama tim, penghargaan
          • Nama event, tanggal, logo event (opsional)
          • Tanda tangan digital (opsional)
      → Atur font size, warna teks per field
      → Klik "Preview" → cek hasil render
      → Simpan template
  → [Event Selesai] → Pilih template
  → "Generate Semua" → [Queue Job berjalan di background]
  → Notifikasi: "Sertifikat siap"
  → Download ZIP atau "Kirim via Email" (Pro ke atas)
```

### 5.6 SaaS Super Admin — Kelola Paket

```
[Login Super Admin]
  → Dashboard Platform (MRR, total tenant, event aktif)
  → Menu "Kelola Paket" → Pilih/buat paket
  → Edit feature values:
      max_events: 10 | certificate_email: true | storage_gb: 50
  → Simpan → Berlaku instan untuk semua tenant di paket tersebut
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
│   │   ├── [org-slug]/
│   │   │   └── [event-slug]/       # Landing page event
│   │   ├── verify/
│   │   │   └── [cert-id]/          # Verifikasi sertifikat publik
│   │   └── scan/
│   │       └── [event-id]/         # QR Scanner check-in
│   ├── (auth)/                     # Login, Register, Reset Password
│   ├── (dashboard)/                # Protected routes
│   │   ├── organizer/              # Dashboard organizer
│   │   │   ├── events/
│   │   │   ├── teams/
│   │   │   ├── schedule/
│   │   │   ├── results/
│   │   │   ├── standings/
│   │   │   ├── tickets/
│   │   │   ├── certificates/
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
│   │   ├── CertificateService.php  # Overlay teks ke background image
│   │   ├── MidtransService.php     # Payment wrapper
│   │   ├── R2StorageService.php    # Cloudflare R2 operations
│   │   └── NotificationService.php # Email sender
│   ├── Jobs/
│   │   ├── GenerateCertificateBatch.php
│   │   ├── SendCertificateEmail.php
│   │   ├── RecalculateStandings.php
│   │   └── GenerateTournamentReport.php
│   └── Events/ + Listeners/
├── routes/
│   ├── api.php                     # API routes (v1)
│   └── webhook.php                 # Midtrans webhook
├── config/
│   ├── jwt.php
│   └── r2.php
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
│   └── {event-id}/
│       ├── backgrounds/{template-id}.jpg      ← background yg diupload organizer
│       └── generated/
│               ├── {cert-id}.pdf
│               └── {cert-id}.png
└── exports/
    └── {event-id}/
        ├── report-{slug}.pdf
        └── data-{slug}.xlsx
```

**Akses Policy:**
- **Public read:** logo, banner, foto pemain, sertifikat yang sudah generate
- **Private (signed URL):** dokumen registrasi, background template sertifikat
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
  description       TEXT,
  contact_email     VARCHAR(255),
  contact_phone     VARCHAR(20),
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

### 7.5 Certificate Tables

```sql
-- CERTIFICATE TEMPLATES
certificate_templates (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id   UUID REFERENCES organizations(id),
  name              VARCHAR(255) NOT NULL,
  background_url    TEXT NOT NULL,                  -- R2: certificates/{event-id}/backgrounds/
  layout            ENUM('landscape','portrait') DEFAULT 'landscape',
  canvas_width      INT NOT NULL,                   -- lebar background dalam pixel
  canvas_height     INT NOT NULL,                   -- tinggi background dalam pixel
  fields_config     JSONB NOT NULL,
  -- fields_config: [
  --   {
  --     "key": "recipient_name",
  --     "label": "Nama Penerima",
  --     "x": 540, "y": 320,
  --     "font_size": 48,
  --     "font_weight": "bold",
  --     "color": "#1a1a1a",
  --     "align": "center",
  --     "max_width": 600
  --   },
  --   { "key": "team_name", "x": 540, "y": 390, ... },
  --   { "key": "award_type", ... },
  --   { "key": "event_name", ... },
  --   { "key": "event_date", ... },
  --   { "key": "logo_event", "x": 80, "y": 60, "width": 120, "height": 120 },
  --   { "key": "signature", "x": 200, "y": 520, "width": 200 },
  --   { "key": "qr_code", "x": 860, "y": 490, "size": 100 }
  -- ]
  show_qr_code      BOOLEAN DEFAULT TRUE,
  show_cert_number  BOOLEAN DEFAULT TRUE,
  created_at        TIMESTAMP DEFAULT NOW(),
  updated_at        TIMESTAMP DEFAULT NOW()
)

-- CERTIFICATES (record per sertifikat yang di-generate)
certificates (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id          UUID REFERENCES events(id),
  template_id       UUID REFERENCES certificate_templates(id),
  cert_number       VARCHAR(50) UNIQUE NOT NULL,     -- COT-2026-00001
  recipient_type    ENUM('team','player'),
  team_id           UUID REFERENCES teams(id),
  player_id         UUID REFERENCES players(id),
  award_type        VARCHAR(100) NOT NULL,
  award_detail      TEXT,
  pdf_url           TEXT,                            -- R2 URL
  png_url           TEXT,                            -- R2 URL
  email_sent_at     TIMESTAMP,
  generated_at      TIMESTAMP DEFAULT NOW()
)
```

### 7.6 Indexes

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
CREATE INDEX idx_certs_number         ON certificates(cert_number);
CREATE INDEX idx_certs_event_id       ON certificates(event_id);
CREATE INDEX idx_plan_features_plan   ON plan_features(plan_id);
CREATE INDEX idx_plan_features_key    ON plan_features(feature_key);
CREATE INDEX idx_refresh_tokens_user  ON user_refresh_tokens(user_id);
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
| **barryvdh/laravel-dompdf** | latest | Export PDF (laporan & sertifikat) |
| **maatwebsite/excel** | 3+ | Export Excel |
| **intervention/image** | 3+ | Image overlay untuk sertifikat (GD/Imagick) |
| **aws/aws-sdk-php** | 3+ | Cloudflare R2 (S3-compatible SDK) |
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

Halaman setup template sertifikat menggunakan pendekatan **overlay preview** sederhana:

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
│  │  X: [540]  Y: [320]                              │  │
│  │  Font size: [48]  Weight: [Bold ▼]               │  │
│  │  Color: [#1a1a1a]  Align: [Center ▼]             │  │
│  └─────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

- Drag field di canvas → update nilai X/Y di panel kanan
- Input X/Y manual di panel → pindah posisi field di canvas
- Tombol "Preview dengan data asli" → request ke server, render gambar nyata, tampil di modal
- Simpan → POST konfigurasi ke API → disimpan di `fields_config` JSONB

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

#### Phase 4 — Generator Sertifikat (Minggu 11–12)

```
api/:
  → Certificate template CRUD (simpan fields_config JSONB)
  → R2 signed URL untuk upload background sertifikat
  → Preview endpoint: terima config → overlay teks ke background → return image
  → GenerateCertificateBatch Job (Queue):
      → Ambil background dari R2
      → Intervention Image: overlay semua field per penerima
      → DomPDF atau GD: generate PDF
      → Upload ke R2: certificates/{event-id}/generated/
  → SendCertificateEmail Job (Queue, paket Pro+)
  → Verifikasi sertifikat endpoint (publik)

web/:
  → Upload background interface
  → Certificate positioner (drag field + input X/Y + panel properti)
  → Tombol "Preview" → tampil hasil render dari server
  → Generate UI (pilih jenis, konfirmasi, progress)
  → Download ZIP / kirim via email
  → Halaman verifikasi sertifikat publik
```

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
```
GET    /v1/events
POST   /v1/events
GET    /v1/events/{id}
PUT    /v1/events/{id}
DELETE /v1/events/{id}
POST   /v1/events/{id}/publish        ← custom action
POST   /v1/certificates/{id}/preview  ← preview render
POST   /v1/certificates/generate      ← trigger batch job
```

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
6. Upload background sertifikat → atur posisi → preview → generate batch
7. Download ZIP sertifikat
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
| **fields_config** | JSONB yang menyimpan posisi & style setiap field sertifikat |
| **Certificate Background** | File gambar (JPG/PNG) yang diupload organizer sebagai template sertifikat |
| **Overlay** | Proses menambahkan teks/logo di atas background image di server |
| **Signed URL** | URL sementara untuk akses/upload file R2 secara langsung |
| **Horizon** | Dashboard monitoring queue Laravel |
| **TenantScope** | Middleware yang memastikan query selalu di-filter per `organization_id` |

### B. Certificate Number Format

```
Format:  COT-{YEAR}-{5DIGIT}
Contoh:  COT-2026-00001

Verifikasi publik:  flo-event.id/verify/COT-2026-00001
```

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

---

*Dokumen ini adalah living document yang diperbarui setiap sprint.*

**Version:** 1.0.0
**Last Updated:** Juni 2026
**Next Review:** Juli 2026
**Owner:** Product Team flo-event
