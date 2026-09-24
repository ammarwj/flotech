{{--
  Laporan pertandingan — the sheet filed once a fixture is over.

  dompdf renders an old subset of HTML/CSS: tables for layout, no flexbox, no
  grid, no `object-fit`, no `max-height`. Every aspect ratio is settled in PHP
  before the image reaches this file (see PdfImageService).

  Two squads side by side = two cells of one table row, not two floats.

  Blank ruled cells are deliberate and are the reason this sheet is worth
  printing: cuaca, temperatur, penonton and warna kostum exist on the paper
  form and nowhere in the database, so they are printed empty for the panitia
  to fill in — the same thing the player album does with Tempat Lahir.

  No formatting helpers are defined here. Blade runs the child section before
  its layout, so every label arrives ready-made from MatchReportService (the
  trap already written up in BillingDocumentService).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pertandingan — {{ $home['team'] }} vs {{ $away['team'] }}</title>
    <style>
        @page { margin: 24pt 26pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #111; }

        table { width: 100%; border-collapse: collapse; }

        /* ---- Masthead ---- */
        .masthead td { vertical-align: middle; padding: 0; }
        /* Square, because PdfImageService pads every logo onto a square canvas:
           dompdf honours neither object-fit nor max-height, so the masthead's
           height has to be settled before the image gets here. */
        .masthead-logo { width: 62pt; text-align: center; }
        .masthead-logo img { width: 56pt; height: 56pt; }
        .masthead-title { text-align: center; }
        .masthead-title h1 { font-size: 16pt; margin: 0; letter-spacing: 0.06em; }
        .masthead-title .event { font-size: 10.5pt; font-weight: bold; margin-top: 2pt; }
        .masthead-title .sub { font-size: 8pt; color: #444; margin-top: 1pt; }
        .rule-thick { border-top: 2.5pt solid #000; margin-top: 5pt; }
        .rule-thin { border-top: 0.75pt solid #000; margin-top: 1.5pt; }

        /* ---- Ruled blocks ---- */
        .grid td, .grid th { border: 0.75pt solid #000; padding: 2.5pt 4pt; }
        .grid th { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.04em; background: #f1f1f1; text-align: center; }
        .grid td { text-align: center; }
        /* A cell the database cannot answer. Ruled and empty on purpose — see
           the note at the top of this file. */
        .blank { height: 13pt; }

        .section { margin-top: 7pt; }
        .section-title { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3pt; }

        /* ---- Scoreline ----
           The crests live here rather than in the masthead: the paper form has
           one club on it and a fixture has two, and a masthead that picked one
           of them would be picking a side. */
        .score td { vertical-align: middle; padding: 2pt 4pt; }
        .score .crest { width: 34pt; text-align: center; }
        .score .crest img { width: 30pt; height: 30pt; }
        .score .team { font-size: 12.5pt; font-weight: bold; }
        .score .team.right { text-align: right; }
        .score .num { font-size: 22pt; font-weight: bold; width: 32pt; text-align: center; }
        .score .dash { width: 10pt; text-align: center; color: #666; }
        /* The period breakdown, centred under the scoreline rather than wedged
           between the two numbers — at half an A4 wide it had nowhere to put a
           column heading, and an unlabelled pair of empty boxes is not a form
           anyone can fill in. */
        .periods { width: 240pt; margin: 4pt auto 0; }

        /* ---- Squads ---- */
        .squads > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
        .squads > tbody > tr > td.left { padding-right: 6pt; }
        .squads > tbody > tr > td.right { padding-left: 6pt; }
        .squad-name { font-size: 9.5pt; font-weight: bold; border: 0.75pt solid #000; border-bottom: none; padding: 4pt 5pt; background: #f1f1f1; }
        .sheet td, .sheet th { border: 0.75pt solid #000; padding: 1.8pt 4pt; font-size: 8pt; }
        .sheet th { font-size: 6.5pt; text-transform: uppercase; background: #f1f1f1; text-align: center; }
        .sheet td.npg { width: 20pt; text-align: center; font-weight: bold; }
        .sheet td.pos { width: 42pt; font-size: 7pt; color: #333; }
        .sheet td.stat, .sheet th.stat { width: 18pt; text-align: center; }
        .sheet td.group { background: #e9e9e9; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
        .sheet td.empty { color: #666; font-style: italic; text-align: center; }

        /* ---- Signatures ----
           The whole sheet is meant to come off the printer as one page: a
           clipboard holds one, and a signature block that spilled onto a second
           would be signed on a page with nothing else on it. That is what the
           tight paddings above are buying. */
        .signatures { margin-top: 12pt; }
        .signatures td { width: 25%; padding: 0 5pt; text-align: center; vertical-align: bottom; }
        .sign-line { border-bottom: 0.75pt solid #000; height: 26pt; }
        .sign-label { font-size: 7.5pt; padding-top: 3pt; }
        .foot { margin-top: 7pt; font-size: 7pt; color: #444; }
        .muted { color: #555; }
    </style>
</head>
<body>

    <table class="masthead">
        <tbody>
            <tr>
                <td class="masthead-logo">
                    @if ($organizerLogo)<img src="{{ $organizerLogo }}" alt="">@endif
                </td>
                <td class="masthead-title">
                    <h1>LAPORAN PERTANDINGAN</h1>
                    <div class="event">{{ $event->name }}</div>
                    <div class="sub">
                        {{ $category?->name }}@if ($phase) &middot; {{ $phase }} @endif
                        @if ($sportLabel) &middot; {{ $sportLabel }} @endif
                    </div>
                </td>
                {{-- Deliberately empty: it balances the organizer's logo so the
                     title stays centred, and the two clubs' crests belong on the
                     scoreline where both of them fit. --}}
                <td class="masthead-logo"></td>
            </tr>
        </tbody>
    </table>
    <div class="rule-thick"></div>
    <div class="rule-thin"></div>

    {{-- Kickoff details. The last three columns have no column in the database
         and are printed blank on purpose. --}}
    <table class="grid section">
        <thead>
            <tr>
                <th style="width: 20%;">Tanggal</th>
                <th style="width: 10%;">Kickoff</th>
                <th style="width: 10%;">Durasi</th>
                <th style="width: 24%;">Stadion / Lapangan</th>
                <th style="width: 12%;">Cuaca</th>
                <th style="width: 12%;">Temperatur</th>
                <th style="width: 12%;">Penonton</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $dateLabel }}</td>
                <td>{{ $kickoffLabel ?: '—' }}</td>
                <td>{{ $durationLabel ?: '—' }}</td>
                <td>{{ $venue ?: '—' }}</td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
            </tr>
        </tbody>
    </table>

    {{-- Scoreline. --}}
    <table class="score section">
        <tbody>
            <tr>
                <td class="crest">@if ($home['logo'])<img src="{{ $home['logo'] }}" alt="">@endif</td>
                <td class="team">{{ $home['team'] }}</td>
                <td class="num">{{ $match->home_score ?? '–' }}</td>
                <td class="dash">—</td>
                <td class="num">{{ $match->away_score ?? '–' }}</td>
                <td class="team right">{{ $away['team'] }}</td>
                <td class="crest">@if ($away['logo'])<img src="{{ $away['logo'] }}" alt="">@endif</td>
            </tr>
        </tbody>
    </table>

    @if ($periods)
        <table class="grid periods">
            <thead>
                <tr>
                    <th style="width: 40%;">Periode</th>
                    <th>{{ $home['team'] }}</th>
                    <th>{{ $away['team'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $period)
                    <tr>
                        <td style="text-align: left;">{{ $period['label'] }}</td>
                        <td class="{{ $period['home'] === '' ? 'blank' : '' }}">{{ $period['home'] }}</td>
                        <td class="{{ $period['away'] === '' ? 'blank' : '' }}">{{ $period['away'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Warna kostum: no column anywhere, so both rows are ruled and empty. --}}
    <table class="grid section">
        <thead>
            <tr>
                <th style="width: 16%;">Warna kostum</th>
                <th>Kaos</th>
                <th>Celana</th>
                <th>Kaos kaki</th>
                <th>Kiper</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([$home['team'], $away['team']] as $teamName)
                <tr>
                    <td style="text-align: left; font-weight: bold;">{{ $teamName }}</td>
                    <td class="blank"></td>
                    <td class="blank"></td>
                    <td class="blank"></td>
                    <td class="blank"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($rubbers)
        <div class="section">
            <div class="section-title">Partai</div>
            <table class="grid">
                <thead>
                    <tr>
                        <th style="width: 34%; text-align: left;">Partai</th>
                        <th style="width: 13%;">{{ $home['team'] }}</th>
                        <th style="width: 13%;">{{ $away['team'] }}</th>
                        <th>Skor set</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rubbers as $rubber)
                        <tr>
                            <td style="text-align: left;">{{ $rubber['label'] }}</td>
                            <td>{{ $rubber['home'] === '' ? '–' : $rubber['home'] }}</td>
                            <td>{{ $rubber['away'] === '' ? '–' : $rubber['away'] }}</td>
                            <td>{{ $rubber['sets'] ?: '–' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Both squads, side by side. --}}
    <table class="squads section">
        <tbody>
            <tr>
                @foreach ([['left', $home], ['right', $away]] as [$align, $side])
                    <td class="{{ $align }}">
                        <div class="squad-name">{{ $side['team'] }}</div>

                        <table class="sheet">
                            <thead>
                                <tr>
                                    <th style="width: 20pt;">No</th>
                                    <th style="width: 42pt;">Pos</th>
                                    <th style="text-align: left;">Nama pemain</th>
                                    @foreach ($columns as $column)
                                        <th class="stat" title="{{ $column['label'] }}">{{ $column['short'] ?: $column['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($side['groups'] as $group)
                                    <tr><td class="group" colspan="{{ 3 + count($columns) }}">{{ $group['label'] }} ({{ count($group['rows']) }})</td></tr>
                                    @forelse ($group['rows'] as $row)
                                        <tr>
                                            <td class="npg">{{ $row['number'] !== '' ? $row['number'] : '–' }}</td>
                                            <td class="pos">{{ $row['position'] ?: '—' }}</td>
                                            <td>{{ $row['name'] }}</td>
                                            @foreach ($columns as $column)
                                                <td class="stat">{{ $row['stats'][$column['key']] !== '' ? $row['stats'][$column['key']] : '' }}</td>
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr><td class="empty" colspan="{{ 3 + count($columns) }}">Tidak ada.</td></tr>
                                    @endforelse
                                @endforeach
                            </tbody>
                        </table>

                        <table class="sheet" style="margin-top: 5pt;">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Ofisial</th>
                                    <th style="width: 72pt;">Jabatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($side['officials'] as $official)
                                    <tr>
                                        <td>{{ $official['name'] }}</td>
                                        <td class="pos" style="width: 72pt;">{{ $official['role'] }}</td>
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

    {{-- Catatan wasit: no column anywhere, and the one thing a referee always
         wants room for. --}}
    <div class="section">
        <div class="section-title">Catatan wasit / kejadian khusus</div>
        <table class="grid">
            <tbody>
                <tr><td class="blank"></td></tr>
                <tr><td class="blank"></td></tr>
            </tbody>
        </table>
    </div>

    {{-- Four signatures. The name lines are blank for the same reason the team
         sheet's are: whoever signs at the table is not necessarily the person
         the system has on file, and printing a name over the line would make
         this document claim something it does not know. --}}
    <table class="signatures">
        <tbody>
            <tr>
                @foreach (['Wasit', 'Pengawas pertandingan', 'Manajer '.$home['team'], 'Manajer '.$away['team']] as $who)
                    <td>
                        <div class="sign-line"></div>
                        <div class="sign-label">{{ $who }}</div>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <p class="foot">
        {{ $confirmed ? 'Hasil sudah dikonfirmasi panitia' : 'Hasil belum dikonfirmasi panitia' }}
        &middot; dicetak {{ $printedAt }}
    </p>
</body>
</html>
