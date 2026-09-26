{{--
  Poster QR pembelian tiket — dicetak lalu ditempel di loket/loker.

  dompdf merender subset HTML/CSS lama: tabel untuk layout, tanpa flexbox/grid,
  `font-family: DejaVu Sans`. QR-nya SVG (php-svg-lib), jadi ia tetap tajam di
  ukuran berapa pun — dan ketajaman itulah yang menentukan kode ini terbaca
  kamera ponsel dari antrean atau tidak.

  Satu halaman, satu event. Ukurannya dipilih untuk dibaca berdiri: judul besar,
  QR ~9cm, dan URL dieja di bawahnya untuk orang yang kameranya bermasalah atau
  berdiri terlalu jauh — tanpa baris itu poster ini tidak punya jalan kedua.

  Tidak ada warna yang load-bearing: poster ini paling sering keluar dari
  fotokopian hitam-putih, sama seperti lembar susunan pemain.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tiket — {{ $event->name }}</title>
    <style>
        @page { margin: 40px 44px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; text-align: center; }
        .muted { color: #6b7280; }
        .logo { height: 70px; }
        .organizer { font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; color: #4b5563; margin-top: 8px; }
        .title { font-size: 34px; font-weight: bold; line-height: 1.15; margin: 10px 0 0; }
        .sub { font-size: 13px; margin-top: 8px; }
        .rule { border-bottom: 3px solid #111827; margin: 18px 0; }
        .cta { font-size: 20px; font-weight: bold; }
        .hint { font-size: 12px; margin-top: 4px; }
        /* Kotak putih dengan garis: quiet zone QR dibuat di sini sebagai padding,
           bukan di renderer — kode yang menempel ke garis tidak terbaca. */
        .qr-box { border: 2px solid #111827; padding: 18px; }
        .qr { width: 250pt; height: 250pt; }
        /* URL-nya dipecah aman: dompdf tidak memotong kata, dan URL panjang yang
           tidak muat akan melebar keluar halaman alih-alih membungkus. */
        .url { font-size: 12px; margin-top: 14px; word-wrap: break-word; }
        /* dompdf memakai shrink-to-fit untuk tabel: tanpa wrapper selebar
           halaman, `align=center` cuma memusatkan isi di dalam cell yang
           selebar isinya sendiri — blok-nya tetap menempel ke kiri. */
        .center { width: 100%; }
        .steps { font-size: 12px; color: #374151; }
        .steps td { padding: 3px 10px; }
        .foot { margin-top: 22px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="">
    @endif
    @if ($organizer)
        <div class="organizer">{{ $organizer }}</div>
    @endif

    <h1 class="title">{{ $event->name }}</h1>
    <div class="sub muted">
        @if ($sportLabel) {{ $sportLabel }} @endif
        @if ($sportLabel && $venue) &middot; @endif
        @if ($venue) {{ $venue }} @endif
    </div>

    <div class="rule"></div>

    <div class="cta">BELI TIKET DI SINI</div>
    <div class="hint muted">Scan QR dengan kamera ponsel</div>

    <table class="center" style="margin-top: 16px;">
        <tr>
            <td align="center">
                <table class="qr-box" style="width: auto; margin: 0 auto;">
                    <tr><td><img class="qr" src="{{ $qr }}" alt=""></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="url">{{ $url }}</div>

    <table class="center" style="margin-top: 18px;">
        <tr>
            <td align="center">
                <table class="steps" style="width: auto; margin: 0 auto;">
                    <tr>
                        <td>1. Scan QR di atas</td>
                        <td>2. Pilih kategori tiket</td>
                        <td>3. Bayar &amp; tiket dikirim ke email</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="foot">{{ $printedAt }}</div>
</body>
</html>
