{{--
  Album pemain: satu tim per halaman (atau lebih, kalau roster-nya panjang),
  grid foto pemain lalu bench ofisial di akhir tim itu.

  dompdf tidak punya flexbox/grid (lihat catatan yang sama di pdf/certificate.blade.php) —
  grid di sini dibangun dari float + width tetap per kartu, bukan CSS grid.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Album Pemain</title>
    <style>
        @page { margin: 28pt 24pt; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; color: #1f2430; }

        .team { }
        .team + .team { page-break-before: always; }

        .team-header { margin-bottom: 14pt; overflow: hidden; }
        .team-logo {
            float: left;
            width: 44pt; height: 44pt;
            border-radius: 6pt;
            object-fit: cover;
            margin-right: 10pt;
        }
        .team-logo-placeholder {
            float: left;
            width: 44pt; height: 44pt;
            border-radius: 6pt;
            background: #eef0f5;
            color: #6b7280;
            text-align: center;
            line-height: 44pt;
            font-size: 16pt;
            font-weight: bold;
            margin-right: 10pt;
        }
        .team-name { font-size: 16pt; font-weight: bold; }
        .team-meta { font-size: 9pt; color: #6b7280; margin-top: 2pt; }

        .section-title {
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            color: #6b7280;
            margin: 10pt 0 8pt 0;
            border-bottom: 0.75pt solid #d8dbe3;
            padding-bottom: 4pt;
        }

        .grid { }
        .grid-clear { clear: both; }
        .card {
            float: left;
            width: 118pt;
            margin: 0 8pt 12pt 0;
            page-break-inside: avoid;
        }
        .card-photo {
            width: 118pt; height: 118pt;
            border-radius: 6pt;
            object-fit: cover;
            border: 0.75pt solid #e2e4ea;
        }
        .card-photo-placeholder {
            width: 118pt; height: 118pt;
            border-radius: 6pt;
            background: #eef0f5;
            color: #6b7280;
            text-align: center;
            line-height: 118pt;
            font-size: 26pt;
            font-weight: bold;
            border: 0.75pt solid #e2e4ea;
        }
        .card-jersey {
            display: inline-block;
            margin-top: 4pt;
            padding: 1pt 5pt;
            border-radius: 3pt;
            background: #1f2430;
            color: #fff;
            font-size: 8pt;
            font-weight: bold;
        }
        .card-name {
            margin-top: 3pt;
            font-size: 9.5pt;
            font-weight: bold;
            line-height: 1.2;
        }
        .card-meta { font-size: 8pt; color: #6b7280; line-height: 1.3; }

        .empty { font-size: 9pt; color: #9aa0ab; font-style: italic; }
    </style>
</head>
<body>
    @foreach ($teams as $team)
        <div class="team">
            <div class="team-header">
                @if ($team['logo'])
                    <img class="team-logo" src="{{ $team['logo'] }}" alt="">
                @else
                    <div class="team-logo-placeholder">{{ mb_substr($team['name'], 0, 1) }}</div>
                @endif
                <div class="team-name">{{ $team['name'] }}</div>
                <div class="team-meta">
                    {{ $event->name }}
                    @if ($team['category'])
                        &middot; {{ $team['category'] }}
                    @endif
                </div>
            </div>

            <div class="section-title">Pemain ({{ count($team['players']) }})</div>
            @if (count($team['players']) === 0)
                <p class="empty">Belum ada pemain terdaftar.</p>
            @else
                <div class="grid">
                    @foreach ($team['players'] as $player)
                        <div class="card">
                            @if ($player['photo'])
                                <img class="card-photo" src="{{ $player['photo'] }}" alt="">
                            @else
                                <div class="card-photo-placeholder">{{ $player['initials'] }}</div>
                            @endif
                            @if ($player['jersey_number'] !== null && $player['jersey_number'] !== '')
                                <div class="card-jersey">No. {{ $player['jersey_number'] }}</div>
                            @endif
                            <div class="card-name">{{ $player['name'] }}</div>
                            <div class="card-meta">
                                @if ($player['position']) {{ $player['position'] }}<br>@endif
                                @if ($player['date_of_birth']) {{ $player['date_of_birth'] }} @endif
                            </div>
                        </div>
                    @endforeach
                    <div class="grid-clear"></div>
                </div>
            @endif

            @if (count($team['officials']) > 0)
                <div class="section-title">Ofisial &amp; Pelatih ({{ count($team['officials']) }})</div>
                <div class="grid">
                    @foreach ($team['officials'] as $official)
                        <div class="card">
                            @if ($official['photo'])
                                <img class="card-photo" src="{{ $official['photo'] }}" alt="">
                            @else
                                <div class="card-photo-placeholder">{{ $official['initials'] }}</div>
                            @endif
                            <div class="card-name">{{ $official['name'] }}</div>
                            <div class="card-meta">{{ $official['role'] }}</div>
                        </div>
                    @endforeach
                    <div class="grid-clear"></div>
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
