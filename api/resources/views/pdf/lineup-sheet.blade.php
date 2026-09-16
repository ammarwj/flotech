{{--
  Lembar susunan pemain untuk meja IP.

  Template pass pertama — dibuat supaya alurnya lengkap, dan memang diniatkan
  diganti nanti. dompdf merender subset HTML/CSS lama: tabel untuk layout,
  tanpa flexbox/grid, `font-family: DejaVu Sans`.

  Dua tim bersisian = dua sel satu baris tabel, bukan dua kolom float.
  Starter dipisah dari cadangan dengan baris sub-judul, bukan dengan warna
  saja: fotokopi hitam-putih adalah bentuk paling umum lembar ini di lapangan,
  dan warna adalah yang pertama hilang di sana.

  Tidak ada helper format yang didefinisikan di sini. Blade menjalankan section
  anak sebelum layout-nya, jadi semua tanggal & label datang sudah jadi dari
  LineupSheetController (jebakan yang sudah tercatat di BillingDocumentService).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Susunan Pemain — {{ $home['team'] }} vs {{ $away['team'] }}</title>
    <style>
        @page { margin: 26px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .meta { font-size: 10px; margin-bottom: 4px; }
        .rule { border-bottom: 2px solid #1a1a1a; margin: 8px 0 12px; }

        table { width: 100%; border-collapse: collapse; }
        /* The two-team row. `top` keeps a short sheet from floating next to a
           long one. */
        .teams > tbody > tr > td { vertical-align: top; width: 50%; padding: 0; }
        .teams > tbody > tr > td.left { padding-right: 9px; }
        .teams > tbody > tr > td.right { padding-left: 9px; }

        .team-name { font-size: 12px; font-weight: bold; padding: 5px 6px; background: #f3f4f6; border: 1px solid #d1d5db; }
        .sheet th, .sheet td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        .sheet th { background: #fafafa; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #4b5563; }
        .sheet td.no { width: 34px; text-align: center; font-weight: bold; }
        .sheet td.pos { width: 78px; color: #4b5563; }
        /* The sub-heading that separates starters from substitutes — the split
           has to survive a black-and-white photocopy, so it is a row of text. */
        .sheet td.group { background: #eef2f7; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
        .sheet td.empty { color: #9ca3af; font-style: italic; }

        .officials { margin-top: 7px; }
        .officials th, .officials td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        .officials th { background: #fafafa; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #4b5563; }
        .officials td.role { width: 96px; color: #4b5563; }

        .signatures { margin-top: 26px; }
        .signatures td { width: 33.33%; padding: 0 8px; text-align: center; vertical-align: bottom; }
        .sign-line { border-bottom: 1px solid #1a1a1a; height: 44px; }
        .sign-label { font-size: 9px; padding-top: 4px; }
        .foot { margin-top: 14px; font-size: 8px; }
    </style>
</head>
<body>
    <h1>Susunan Pemain</h1>
    <div class="meta">
        <strong>{{ $event->name }}</strong>
        @if ($category) &middot; {{ $category->name }} @endif
        @if ($phase) &middot; {{ $phase }} @endif
    </div>
    <div class="meta muted">
        {{ $dateLabel }}@if ($timeLabel) &middot; {{ $timeLabel }} @endif
        @if ($match->venue) &middot; {{ $match->venue }}
        @elseif ($event->location_name) &middot; {{ $event->location_name }} @endif
    </div>
    <div class="rule"></div>

    <table class="teams">
        <tbody>
            <tr>
                @foreach ([['left', $home, 'Tuan rumah'], ['right', $away, 'Tim tamu']] as [$align, $side, $label])
                    <td class="{{ $align }}">
                        <div class="team-name">{{ $side['team'] }} <span class="muted">— {{ $label }}</span></div>

                        <table class="sheet">
                            <thead>
                                <tr>
                                    <th style="width: 34px; text-align: center;">No.</th>
                                    <th>Nama</th>
                                    <th style="width: 78px;">Posisi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([['Pemain inti', $side['starters']], ['Cadangan', $side['substitutes']]] as [$groupLabel, $rows])
                                    <tr><td class="group" colspan="3">{{ $groupLabel }} ({{ count($rows) }})</td></tr>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="no">{{ $row['number'] ?: '–' }}</td>
                                            <td>{{ $row['name'] }}</td>
                                            <td class="pos">{{ $row['position'] ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td class="empty" colspan="3">Tidak ada.</td></tr>
                                    @endforelse
                                @endforeach
                            </tbody>
                        </table>

                        <table class="officials">
                            <thead>
                                <tr>
                                    <th>Ofisial di bangku</th>
                                    <th style="width: 96px;">Jabatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($side['officials'] as $official)
                                    <tr>
                                        <td>{{ $official['name'] }}</td>
                                        <td class="role">{{ $official['role'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="empty" colspan="2">Tidak ada.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    {{-- Tiga tanda tangan: wasit dan kedua manajer. Kolom nama sengaja kosong —
         yang tanda tangan di meja IP belum tentu orang yang namanya tercatat di
         sistem, dan mencetak nama di atas garis membuat lembar ini mengklaim
         sesuatu yang tidak diketahuinya. --}}
    <table class="signatures">
        <tbody>
            <tr>
                @foreach (['Wasit', 'Manajer '.$home['team'], 'Manajer '.$away['team']] as $who)
                    <td>
                        <div class="sign-line"></div>
                        <div class="sign-label">{{ $who }}</div>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <p class="foot muted">
        Disetujui wasit lewat sistem &middot; dicetak {{ $printedAt }}
    </p>
</body>
</html>
