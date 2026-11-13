{{--
  The organizer's artwork, with the data printed on top.

  dompdf renders an old HTML/CSS subset — no flexbox, no transforms — but it is
  reliable at absolute positioning, which is the whole layout here. Coordinates
  are stored as percentages (so a template survives any paper size) and resolved
  to points against the page below.

  Anchoring without transform: the field's x is the point the text aligns *to*,
  not the corner of a box. A left-aligned field starts at x; a right-aligned one
  ends at x; a centred one is centred on x — done by giving the box a width of
  2*x (or 2*(100-x) past the midpoint) so the box's own centre lands on x.
--}}
@php
    $portrait = ($template->orientation ?? 'landscape') === 'portrait';
    $pageW = $portrait ? 595 : 842; // A4 in points
    $pageH = $portrait ? 842 : 595;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
        .sheet { position: relative; width: {{ $pageW }}pt; height: {{ $pageH }}pt; }
        .bg { position: absolute; top: 0; left: 0; width: {{ $pageW }}pt; height: {{ $pageH }}pt; }
    </style>
</head>
<body>
<div class="sheet">
    @if ($background)
        <img class="bg" src="{{ $background }}" alt="">
    @endif

    @foreach ($template->fields as $field)
        @php
            $key = $field['key'] ?? null;
        @endphp
        @continue(! $key)

        @php
            $x = (float) ($field['x'] ?? 50);
            $y = (float) ($field['y'] ?? 50);
            /*
             * The size CertificateService::fittedSizes() worked out, which is
             * the field's own size unless it has a box and a value too long for
             * it. Looked up by index rather than recomputed, because Blade
             * cannot measure text and a second rule here would be a second
             * answer that drifts from the one the editor previews.
             */
            $size = (float) ($sizes[$loop->index] ?? $field['size'] ?? 18);
            $align = $field['align'] ?? 'center';

            $top = $y / 100 * $pageH;
        @endphp

        @if ($key === 'qr')
            {{-- The QR is a square anchored by its top-left corner. --}}
            <img src="{{ $qr }}" alt="" style="
                position: absolute;
                left: {{ $x / 100 * $pageW }}pt;
                top: {{ $top }}pt;
                width: {{ $size * 3 }}pt;
                height: {{ $size * 3 }}pt;
            ">
            @continue
        @endif

        @php
            $value = $values[$key] ?? '';
        @endphp
        @continue($value === '')

        @php
            $wrapPercent = (float) ($field['w'] ?? $field['wrap'] ?? 0);

            /*
             * Two ways to build the box, and the older one has to stay.
             *
             * Without a width, the only way to put the alignment point on x
             * without `transform` (which dompdf does not support) is to give
             * the box a width of 2*x — so its own centre lands on x. Every
             * template saved before `w` existed is laid out that way, and it
             * means the text may wrap anywhere up to half the page.
             *
             * With a width, the box is stated outright and anchored off x by
             * the alignment, which is what makes the wrap — and therefore the
             * fitted size CertificateService worked out — match what prints.
             */
            $box = $wrapPercent > 0
                ? (function () use ($align, $x, $wrapPercent, $pageW) {
                    $w = $wrapPercent / 100 * $pageW;
                    $left = match ($align) {
                        'left' => $x / 100 * $pageW,
                        'right' => $x / 100 * $pageW - $w,
                        default => $x / 100 * $pageW - $w / 2,
                    };

                    return sprintf('left: %.2fpt; width: %.2fpt;', $left, $w);
                })()
                : match ($align) {
                    'left' => sprintf('left: %.2fpt; width: %.2fpt;', $x / 100 * $pageW, (100 - $x) / 100 * $pageW),
                    'right' => sprintf('left: 0pt; width: %.2fpt;', $x / 100 * $pageW),
                    default => $x <= 50
                        ? sprintf('left: 0pt; width: %.2fpt;', 2 * $x / 100 * $pageW)
                        : sprintf('right: 0pt; width: %.2fpt;', 2 * (100 - $x) / 100 * $pageW),
                };
        @endphp

        {{--
          `h` deliberately does NOT become a CSS height. Certificate text has no
          vertical anchor — it always grows downwards from y — so the box height
          means "how far down this may grow", which is exactly the limit the
          size was fitted against. Rendering it as a height would add a
          clipping edge the editor never showed anyone.
        --}}

        <div style="
            position: absolute;
            top: {{ $top }}pt;
            {{ $box }}
            text-align: {{ $align }};
            font-size: {{ $size }}pt;
            line-height: 1.2;
            color: {{ $field['color'] ?? '#1f2430' }};
            font-weight: {{ ($field['bold'] ?? false) ? 'bold' : 'normal' }};
            {{ ($field['uppercase'] ?? false) ? 'text-transform: uppercase;' : '' }}
        ">{{ $value }}</div>
    @endforeach
</div>
</body>
</html>
