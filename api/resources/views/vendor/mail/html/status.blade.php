@props(['type' => 'info', 'title'])
{{--
    The verdict of the mail, stated once and coloured: approved, rejected, paid,
    refunded. Someone skimming on a phone should know the answer without reading
    a sentence. type: success | error | warning | info (see themes/flo.css).
--}}
<table class="status status-{{ $type }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="status-cell">
<p class="status-title">{{ $title }}</p>
<div class="status-body">{{ Illuminate\Mail\Markdown::parse($slot) }}</div>
</td>
</tr>
</table>
