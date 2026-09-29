{{--
  Album pemain: satu tim per halaman, bentuk formulir cetak — kop dua logo,
  lalu satu tabel bergaris berisi DAFTAR OFFICIAL (A) menyusul DAFTAR PEMAIN (B),
  masing-masing satu baris per orang: nomor, kotak foto 3x4, blok data diri,
  kolom keterangan.

  Nomornya menerus dari ofisial ke pemain (1,2,3 ofisial lalu 4 pemain) karena
  ini satu daftar kontingen, bukan dua daftar yang kebetulan bersebelahan.

  dompdf tidak punya flexbox/grid (lihat catatan yang sama di pdf/certificate.blade.php)
  dan rowspan-nya rapuh: kop dibangun dari <table>, dan blok data diri tiap orang
  adalah tabel bersarang di dalam satu <td> — bukan rowspan.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Album Pemain</title>
    <style>
        @page { margin: 26pt 28pt; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; color: #000; font-size: 9pt; }

        .team { }
        .team + .team { page-break-before: always; }

        /* Kop: logo kiri (penyelenggara), judul, logo kanan (klub). */
        .masthead { width: 100%; border-collapse: collapse; }
        .masthead td { border: none; padding: 0; vertical-align: middle; }
        /* Square, because TeamAlbumService pads every logo onto a square
           canvas — dompdf honours neither object-fit nor max-height, so the
           masthead's height has to be settled before the image gets here. */
        .masthead-logo { width: 64pt; text-align: center; }
        .masthead-logo img { width: 58pt; height: 58pt; }
        .masthead-title { text-align: center; padding: 0 6pt; }

        .doc-title { font-size: 17pt; font-weight: bold; letter-spacing: 0.5pt; }
        .doc-event { font-size: 12.5pt; font-weight: bold; margin-top: 1pt; }
        .doc-place { font-size: 7.5pt; font-weight: bold; margin-top: 3pt; }

        /* Garis tebal-tipis di bawah kop, seperti kop surat. */
        .rule-thick { border-top: 3pt solid #000; margin-top: 4pt; }
        .rule-thin { border-top: 0.75pt solid #000; margin-top: 1.5pt; }

        .club-row { width: 100%; border-collapse: collapse; margin: 14pt 0 5pt 0; }
        /* Baseline, bukan top: nama klub yang panjang melipat jadi dua baris,
           dan kontak yang ikut naik ke atas bersamanya terbaca seperti judul
           kedua alih-alih pasangan sebaris. */
        .club-row td { border: none; padding: 0; vertical-align: bottom; }
        /* Label kecil di atas nilainya, dua kolom bentuknya sama persis —
           dulu labelnya sebaris (12pt) sementara kontaknya 9.5pt, jadi kolom
           kanan terbaca seperti catatan kaki yang kebetulan sejajar. */
        .club-label { font-size: 6.5pt; font-weight: bold; letter-spacing: 1pt; color: #555; }
        .club-value { font-size: 9pt; font-weight: bold; margin-top: 1pt; }
        /* nowrap + lebar minimum: kontak selalu utuh satu baris, dan nama klub
           — satu-satunya kolom yang lentur — yang melipat kalau kehabisan
           tempat. Sebaliknya nomor telepon terpotong di tengah. */
        .club-contact { width: 1%; white-space: nowrap; text-align: right; padding-left: 16pt !important; }

        table.roster { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        table.roster td, table.roster th { border: 0.75pt solid #000; padding: 4pt 5pt; }

        .col-no { width: 6%; text-align: center; }
        .col-photo { width: 20%; text-align: center; }
        .col-data { width: 60%; padding: 0 !important; }
        .col-ket { width: 14%; }

        thead th { font-size: 8.5pt; font-weight: bold; text-align: center; }

        .section-row td { font-weight: bold; font-size: 9.5pt; }
        .section-letter { width: 7%; text-align: center; }

        .person { page-break-inside: avoid; }
        .person-no { text-align: center; font-size: 10pt; vertical-align: middle; }
        .person-photo { text-align: center; vertical-align: middle; }
        .person-ket { vertical-align: middle; }

        /* Kotak foto 3x4: bergaris oranye saat kosong supaya pencetak tahu
           persis di mana foto ditempel, terisi gambar saat pesertanya mengunggah. */
        .photo-box {
            width: 66pt; height: 88pt;
            border: 1.5pt solid #E8722A;
            margin: 2pt auto;
        }
        .photo-box img { width: 66pt; height: 88pt; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data td { border: 0.75pt solid #000; padding: 2pt 6pt; font-size: 8.5pt; vertical-align: middle; }
        table.data td.label { width: 32%; color: #333; }
        table.data td.value { width: 68%; font-weight: bold; }
        /* Baris terluar sudah digambar sel induknya; buang yang dobel. */
        table.data tr:first-child td { border-top: none; }
        table.data tr:last-child td { border-bottom: none; }
        table.data td:first-child { border-left: none; }
        table.data td:last-child { border-right: none; }

        .empty-row td { font-style: italic; color: #555; text-align: center; }
    </style>
</head>
@php
    /**
     * Padding vertikal tiap baris data, dihitung dari jumlah barisnya.
     *
     * Sel orang setinggi kotak foto (92pt) sementara jumlah barisnya
     * berubah-ubah — ofisial 2, pemain 3, plus berapa pun field dinamis yang
     * organizer centang. Sisa tinggi yang tidak terpakai menggantung sebagai
     * kotak kosong yang terbaca "lupa diisi".
     *
     * Padding, bukan `height` per baris: dompdf mengabaikan tinggi baris dan
     * memusatkan tabel anaknya secara vertikal, yang justru menghasilkan gap di
     * atas DAN di bawah. Minimum 2pt supaya baris yang sudah lewat 92pt (banyak
     * field dinamis) tetap terbaca, bukan gepeng.
     */
    $rowPad = fn (int $rows) => max(2, round((92 / max(1, $rows) - 11) / 2, 2));
@endphp
<body>
    @foreach ($teams as $team)
        <div class="team">
            <table class="masthead">
                <tr>
                    <td class="masthead-logo">
                        @if ($team['organizer_logo'])
                            <img src="{{ $team['organizer_logo'] }}" alt="">
                        @endif
                    </td>
                    <td class="masthead-title">
                        <div class="doc-title">ALBUM PEMAIN</div>
                        <div class="doc-event">{{ mb_strtoupper($event->name) }}</div>
                        @if ($team['venue'])
                            <div class="doc-place">{{ $team['venue'] }}</div>
                        @endif
                    </td>
                    <td class="masthead-logo">
                        @if ($team['logo'])
                            <img src="{{ $team['logo'] }}" alt="">
                        @endif
                    </td>
                </tr>
            </table>
            <div class="rule-thick"></div>
            <div class="rule-thin"></div>

            {{-- Dua kolom, bukan satu baris: nama klub bisa panjang dan dompdf
                 tidak punya flex, jadi tabel yang memisahkannya. --}}
            <table class="club-row">
                <tr>
                    <td>
                        <div class="club-label">NAMA KLUB</div>
                        <div class="club-value">{{ $team['name'] }}</div>
                    </td>
                    @if ($team['contact'])
                        <td class="club-contact">
                            <div class="club-label">KONTAK</div>
                            <div class="club-value">{{ $team['contact'] }}</div>
                        </td>
                    @endif
                </tr>
            </table>

            @php $no = 0; @endphp

            <table class="roster">
                <tbody>
                    <tr class="section-row">
                        <td class="section-letter">A.</td>
                        <td colspan="3">DAFTAR OFFICIAL</td>
                    </tr>
                    <tr>
                        <th class="col-no">NO</th>
                        <th class="col-photo">PHOTO 3 X 4</th>
                        <th class="col-data" style="padding: 4pt 5pt !important;">DATA DIRI</th>
                        <th class="col-ket">KET</th>
                    </tr>

                    @forelse ($team['officials'] as $official)
                        @php $no++; @endphp
                        <tr class="person">
                            <td class="col-no person-no">{{ $no }}.</td>
                            <td class="col-photo person-photo">
                                <div class="photo-box">
                                    @if ($official['photo'])
                                        <img src="{{ $official['photo'] }}" alt="">
                                    @endif
                                </div>
                            </td>
                            <td class="col-data">
                                @php $pad = $rowPad(2 + count($official['extra'])); @endphp
                                <table class="data" style="padding: 0">
                                    <tr><td class="label" style="padding: {{ $pad }}pt 6pt">Nama</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $official['name'] }}</td></tr>
                                    <tr><td class="label" style="padding: {{ $pad }}pt 6pt">Jabatan</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $official['role'] }}</td></tr>
                                    {{-- Field dinamis yang organizer centang "Tampilkan di album
                                         pemain". Nilai kosong tetap dicetak — lembar ini
                                         dilengkapi tangan di lapangan. --}}
                                    @foreach ($official['extra'] as $row)
                                        <tr><td class="label" style="padding: {{ $pad }}pt 6pt">{{ $row['label'] }}</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $row['value'] }}</td></tr>
                                    @endforeach
                                </table>
                            </td>
                            <td class="col-ket person-ket">&nbsp;</td>
                        </tr>
                    @empty
                        <tr class="empty-row">
                            <td colspan="4">Belum ada ofisial terdaftar.</td>
                        </tr>
                    @endforelse

                    <tr class="section-row">
                        <td class="section-letter">B.</td>
                        <td colspan="3">DAFTAR PEMAIN</td>
                    </tr>

                    @forelse ($team['players'] as $player)
                        @php $no++; @endphp
                        <tr class="person">
                            <td class="col-no person-no">{{ $no }}.</td>
                            <td class="col-photo person-photo">
                                <div class="photo-box">
                                    @if ($player['photo'])
                                        <img src="{{ $player['photo'] }}" alt="">
                                    @endif
                                </div>
                            </td>
                            <td class="col-data">
                                @php $pad = $rowPad(3 + count($player['extra'])); @endphp
                                <table class="data" style="padding: 0">
                                    <tr><td class="label" style="padding: {{ $pad }}pt 6pt">Nama</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $player['name'] }}</td></tr>
                                    <tr><td class="label" style="padding: {{ $pad }}pt 6pt">Posisi bermain</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $player['position'] }}</td></tr>
                                    <tr><td class="label" style="padding: {{ $pad }}pt 6pt">No punggung</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $player['jersey_number'] }}</td></tr>
                                    @foreach ($player['extra'] as $row)
                                        <tr><td class="label" style="padding: {{ $pad }}pt 6pt">{{ $row['label'] }}</td><td class="value" style="padding: {{ $pad }}pt 6pt">{{ $row['value'] }}</td></tr>
                                    @endforeach
                                </table>
                            </td>
                            <td class="col-ket person-ket">&nbsp;</td>
                        </tr>
                    @empty
                        <tr class="empty-row">
                            <td colspan="4">Belum ada pemain terdaftar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
