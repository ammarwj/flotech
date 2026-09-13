@props(['url', 'logo' => null, 'brand' => null])
{{--
    The uploaded logo when there is one, the text lockup otherwise.

    Mail clients block remote images by default, so the image carries the brand
    name as its `alt` — a blocked logo still reads as "flo-event" rather than an
    empty box. That is what keeps this from regressing the reason the header was
    text-only to begin with: a wordmark always shows, one way or another.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" alt="{{ $brand }}" height="34" style="height: 34px; max-height: 34px; border: 0;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
