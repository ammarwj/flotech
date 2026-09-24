{{--
  Laporan pertandingan — the sheet filed once a fixture is over, laid out after
  the paper MATCH SUMMARY the panitia already uses.

  dompdf renders an old subset of HTML/CSS: tables for layout, no flexbox, no
  grid, no `object-fit`, no `max-height`. Every aspect ratio is settled in PHP
  before the image reaches this file (see PdfImageService).

  Two squads side by side = two cells of one table row, not two floats.

  Blank ruled cells are deliberate and are the reason this sheet is worth
  printing: NP, cuaca, temperatur, penonton, warna kostum, the extra-time row
  and the substitution minute exist on the paper form and nowhere in the
  database, so they are printed empty for the panitia to fill in — the same
  thing the player album does with Tempat Lahir.

  No formatting helpers are defined here. Blade runs the child section before
  its layout, so every label arrives ready-made from MatchReportService (the
  trap already written up in BillingDocumentService).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Match Summary — {{ $home['team'] }} vs {{ $away['team'] }}</title>
    <style>
        @page { margin: 22pt 24pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #111; }

        table { width: 100%; border-collapse: collapse; }

        /* ---- Masthead ---- */
        .masthead td { vertical-align: middle; padding: 0; }
        /* Square, because PdfImageService pads every logo onto a square canvas:
           dompdf honours neither object-fit nor max-height, so the masthead's
           height has to be settled before the image gets here. */
        .masthead-logo { width: 62pt; text-align: center; }
        .masthead-logo img { width: 52pt; height: 52pt; }
        /* The right-hand crest is the *host* club, which is what the paper form
           carries there — not an arbitrary pick between the two sides. The
           caption says so out loud, because a crest alone beside a title reads
           as "this document belongs to them". The away crest sits on its own
           squad band below, where a reader looks for it. */
        .masthead-logo .who { font-size: 5.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #555; margin-top: 1pt; }
        .masthead-title { text-align: center; }
        .masthead-title h1 { font-size: 17pt; margin: 0; letter-spacing: 0.1em; }
        .masthead-title .event { font-size: 10.5pt; font-weight: bold; margin-top: 2pt; text-transform: uppercase; }
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

        .section { margin-top: 6pt; }
        .section-title { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3pt; }

        /* ---- Scoreline ----
           The period breakdown sits *between* the two big numbers, home value
           to the left of each label and away to the right, the way the form
           reads: one row per period, no column headings needed because the side
           a number is on says whose it is. */
        .score td { vertical-align: middle; padding: 2pt 3pt; }
        .score .team { font-size: 12pt; font-weight: bold; }
        .score .team.right { text-align: right; }
        .score .num { font-size: 26pt; font-weight: bold; width: 34pt; text-align: center; }
        .periods { width: 184pt; }
        .periods td { border: 0.75pt solid #000; padding: 1.6pt 3pt; font-size: 7.5pt; text-align: center; }
        .periods td.label { background: #f1f1f1; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; width: 84pt; }
        .periods td.val { width: 32pt; height: 12pt; font-weight: bold; }

        /* ---- Squads ---- */
        .squads > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
        .squads > tbody > tr > td.left { padding-right: 5pt; }
        .squads > tbody > tr > td.right { padding-left: 5pt; }
        .squad-name { border: 0.75pt solid #000; border-bottom: none; padding: 3pt 5pt; background: #f1f1f1; }
        .squad-name td { border: 0; padding: 0; vertical-align: middle; }
        .squad-name .crest { width: 20pt; }
        .squad-name .crest img { width: 16pt; height: 16pt; }
        .squad-name .name { font-size: 9.5pt; font-weight: bold; }
        .band { border: 0.75pt solid #000; border-bottom: none; background: #e4e4e4; padding: 2pt 5pt; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; }
        .sheet td, .sheet th { border: 0.75pt solid #000; padding: 1.8pt 3pt; font-size: 8pt; text-align: center; }
        .sheet th { font-size: 6pt; text-transform: uppercase; background: #f1f1f1; }
        .sheet td.npg { width: 18pt; font-weight: bold; }
        .sheet td.pos { width: 26pt; font-size: 7pt; color: #333; }
        .sheet td.stat, .sheet th.stat { width: 15pt; }
        /* The minute a player came off or on. Ruled and empty for the same
           reason cuaca is: the schema has no minute anywhere. */
        .sheet td.sub { width: 20pt; }
        .sheet td.empty { color: #666; font-style: italic; }
        .sheet td.name { text-align: center; }
        .legend { font-size: 6pt; color: #555; padding: 2pt 1pt 0; }

        /* ---- Signatures ----
           The whole sheet is meant to come off the printer as one page: a
           clipboard holds one, and a signature block that spilled onto a second
           would be signed on a page with nothing else on it. That is what the
           tight paddings above are buying. */
        .signatures { margin-top: 10pt; }
        .signatures td { width: 25%; padding: 0 5pt; text-align: center; vertical-align: bottom; }
        .sign-line { border-bottom: 0.75pt solid #000; height: 24pt; }
        .sign-label { font-size: 7.5pt; padding-top: 3pt; }
        .foot { margin-top: 6pt; font-size: 7pt; color: #444; }
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
                    <h1>MATCH SUMMARY</h1>
                    <div class="event">{{ $event->name }}</div>
                    <div class="sub">
                        {{ $category?->name }}@if ($phase) &middot; {{ $phase }} @endif
                        @if ($sportLabel) &middot; {{ $sportLabel }} @endif
                    </div>
                </td>
                <td class="masthead-logo">
                    @if ($home['logo'])
                        <img src="{{ $home['logo'] }}" alt="">
                        <div class="who">Tuan rumah</div>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
    <div class="rule-thick"></div>
    <div class="rule-thin"></div>

    {{-- Kickoff details. NP, cuaca, temperatur and penonton have no column in
         the database and are printed blank on purpose. --}}
    <table class="grid section">
        <thead>
            <tr>
                <th style="width: 7%;">NP</th>
                <th style="width: 23%;">Tgl &amp; Kickoff</th>
                <th style="width: 13%;">Durasi (menit)</th>
                <th style="width: 25%;">Stadion</th>
                <th style="width: 11%;">Cuaca</th>
                <th style="width: 11%;">Temperatur</th>
                <th style="width: 10%;">Penonton</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="blank"></td>
                <td>{{ $scheduleLabel }}</td>
                {{-- Normal time from the sport, then two boxes for the extra-time
                     halves — printed empty because no extra-time minute is
                     stored anywhere, not because none was played. --}}
                <td style="white-space: nowrap;">{{ $durationLabel ?: '—' }} (&nbsp;&nbsp;) (&nbsp;&nbsp;)</td>
                <td>{{ $venue ?: '—' }}</td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
            </tr>
        </tbody>
    </table>

    {{-- Scoreline, with the periods between the two numbers. --}}
    <table class="score section">
        <tbody>
            <tr>
                <td class="team">{{ $home['team'] }}</td>
                <td class="num">{{ $match->home_score ?? '–' }}</td>
                <td style="width: 184pt;">
                    @if ($periods)
                        <table class="periods">
                            <tbody>
                                @foreach ($periods as $period)
                                    <tr>
                                        <td class="val">{{ $period['home'] === '' ? '' : $period['home'] }}</td>
                                        <td class="label">{{ $period['label'] }}</td>
                                        <td class="val">{{ $period['away'] === '' ? '' : $period['away'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </td>
                <td class="num">{{ $match->away_score ?? '–' }}</td>
                <td class="team right">{{ $away['team'] }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Warna kostum: one row, home's three to the left of the label and away's
         three to the right, as on the form. No column anywhere, so every value
         cell is ruled and empty. The tiny captions are what make the boxes
         fillable — the form assumes a convention this printout cannot. --}}
    <table class="grid section">
        <thead>
            <tr>
                <th colspan="3">{{ $home['team'] }}</th>
                <th rowspan="2" style="width: 22%;">Warna kostum</th>
                <th colspan="3">{{ $away['team'] }}</th>
            </tr>
            <tr>
                <th style="width: 13%;">Kaos</th>
                <th style="width: 13%;">Celana</th>
                <th style="width: 13%;">Kaos kaki</th>
                <th style="width: 13%;">Kaos</th>
                <th style="width: 13%;">Celana</th>
                <th style="width: 13%;">Kaos kaki</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
                <td class="blank"></td>
            </tr>
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

    {{-- Both squads, side by side. Each group — inti, cadangan, lainnya — is its
         own table under its own band, so PEMAIN CADANGAN repeats the column
         headings instead of inheriting them from a row the reader has to scroll
         back to. --}}
    <table class="squads section">
        <tbody>
            <tr>
                @foreach ([['left', $home], ['right', $away]] as [$align, $side])
                    <td class="{{ $align }}">
                        <table class="squad-name">
                            <tbody>
                                <tr>
                                    <td class="crest">@if ($side['logo'])<img src="{{ $side['logo'] }}" alt="">@endif</td>
                                    <td class="name">{{ $side['team'] }}</td>
                                </tr>
                            </tbody>
                        </table>

                        @foreach ($side['groups'] as $group)
                            <div class="band">{{ $group['label'] }} ({{ count($group['rows']) }})</div>
                            <table class="sheet">
                                <thead>
                                    <tr>
                                        <th style="width: 18pt;">NPG</th>
                                        <th style="width: 26pt;">Pos</th>
                                        <th>Nama pemain</th>
                                        @foreach ($columns as $column)
                                            <th class="stat" title="{{ $column['label'] }}">{{ $column['short'] ?: $column['label'] }}</th>
                                        @endforeach
                                        <th style="width: 20pt;" title="Menit pergantian">S</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($group['rows'] as $row)
                                        <tr>
                                            <td class="npg">{{ $row['number'] !== '' ? $row['number'] : '–' }}</td>
                                            <td class="pos">{{ $row['position'] ?: '—' }}</td>
                                            <td class="name">{{ $row['name'] }}</td>
                                            @foreach ($columns as $column)
                                                <td class="stat">{{ $row['stats'][$column['key']] !== '' ? $row['stats'][$column['key']] : '' }}</td>
                                            @endforeach
                                            <td class="sub"></td>
                                        </tr>
                                    @empty
                                        <tr><td class="empty" colspan="{{ 4 + count($columns) }}">Tidak ada.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        @endforeach

                        <div class="band" style="margin-top: 4pt;">Ofisial</div>
                        <table class="sheet">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th style="width: 72pt;">Jabatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($side['officials'] as $official)
                                    <tr>
                                        <td class="name">{{ $official['name'] }}</td>
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

    @if ($positionLegend)
        {{-- A literal middle dot, not the entity: {{ }} escapes, so "&middot;"
             would print as its own source text. --}}
        <div class="legend">POS: {{ implode(' · ', $positionLegend) }} · S: menit pergantian</div>
    @endif

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
