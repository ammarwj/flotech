@props(['url'])
{{--
    A text lockup, not an <img>. Mail clients block remote images by default and a
    logo that doesn't load reads as a broken email — a wordmark always shows.
    Mirrors web/components/shared/logo.tsx: "flo" in ink, "-event" in brand.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
