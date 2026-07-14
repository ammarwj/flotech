@php
    // "flo-event" → "flo" + a brand-coloured "-event", the same lockup the web app
    // draws. A name without a dash just renders whole.
    $brand = (string) config('brand.name');
    $head = \Illuminate\Support\Str::before($brand, '-');
    $tail = \Illuminate\Support\Str::after($brand, $head);
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('brand.url')">
{{ $head }}<span class="brand-accent">{{ $tail }}</span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ config('brand.tagline') }}

Ada yang tidak beres? Hubungi [{{ config('brand.support_email') }}](mailto:{{ config('brand.support_email') }}).

© {{ date('Y') }} {{ $brand }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
