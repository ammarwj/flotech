{{--
  Generic table export. dompdf renders an old HTML/CSS subset: tables for
  layout, no flexbox or grid.

  Deliberately dumb — it prints whatever headings and rows the EventExport hands
  it, so a new export needs no template of its own and cannot render a header
  row that disagrees with the values under it.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $event->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .meta { font-size: 10px; margin-bottom: 14px; }
        {{--
          `table-layout: fixed` so the columns divide the page evenly instead of
          dompdf widening whichever one holds the longest cell. Without it a
          single long answer — a medical note, a waiver label — takes a third of
          the sheet and squeezes every other column into one character per line.
          The page itself is already sized to the column count
          (EventExport::paper()), so an even division is the right one.
        --}}
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 5px 6px;
            text-align: left;
            /* Long answers wrap rather than run past their column. dompdf needs
               both spellings; neither alone breaks an unspaced string. */
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: top;
        }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; }
        tr:nth-child(even) td { background: #fafafa; }
        .foot { margin-top: 16px; font-size: 9px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta muted">
        {{ $event->name }} &middot; {{ $event->organization->name ?? '—' }} &middot;
        dicetak {{ now()->timezone(config('wallet.timezone'))->translatedFormat('d F Y, H:i') }}
    </div>

    @if (count($rows) === 0)
        <p class="muted">Belum ada data untuk diekspor.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="foot muted">{{ count($rows) }} baris.</p>
    @endif
</body>
</html>
