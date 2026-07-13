{{--
  Shared layout for the subscription billing documents. dompdf renders an old
  HTML/CSS subset: tables for layout, no flexbox or grid.
--}}
@php
    $org = $subscription->organization;
    $plan = $subscription->plan;
    $cycle = $subscription->billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2430; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #6b7280; }
        .head-title { font-size: 26px; font-weight: bold; letter-spacing: 1px; text-align: right; }
        .head-number { text-align: right; margin-top: 4px; }
        .issuer-name { font-size: 15px; font-weight: bold; }
        .section { margin-top: 28px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .key { width: 120px; }
        .items { margin-top: 10px; }
        .items th { background: #f3f4f6; text-align: left; padding: 8px 10px; border-bottom: 1px solid #d1d5db; }
        .items td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
        .right, .items th.right { text-align: right; }
        .total td { padding: 10px; font-size: 13px; font-weight: bold; border-bottom: 2px solid #1f2430; }
        .stamp { margin-top: 26px; display: inline-block; border: 3px solid #15803d; color: #15803d;
                 font-size: 20px; font-weight: bold; letter-spacing: 3px; padding: 6px 18px; transform: rotate(-4deg); }
        .footer { margin-top: 36px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div class="issuer-name">{{ $issuer['issuer_name'] }}</div>
                <div class="muted">{{ $issuer['issuer_address'] }}</div>
                <div class="muted">{{ $issuer['issuer_email'] }}</div>
                @if (! empty($issuer['issuer_npwp']))
                    <div class="muted">NPWP: {{ $issuer['issuer_npwp'] }}</div>
                @endif
            </td>
            <td style="vertical-align: top;">
                <div class="head-title">@yield('title')</div>
                <div class="head-number muted">@yield('number')</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <table>
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    <div class="muted">Ditagihkan kepada</div>
                    <div style="font-weight: bold; margin-top: 4px;">{{ $org->name }}</div>
                    @if ($org->contact_email)
                        <div class="muted">{{ $org->contact_email }}</div>
                    @endif
                    @if ($org->contact_phone)
                        <div class="muted">{{ $org->contact_phone }}</div>
                    @endif
                </td>
                <td style="vertical-align: top;">
                    <table class="meta">
                        @yield('meta')
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <tr>
            <th>Deskripsi</th>
            <th style="width: 120px;">Periode</th>
            <th class="right" style="width: 140px;">Jumlah</th>
        </tr>
        <tr>
            <td>
                <div style="font-weight: bold;">Langganan paket {{ $plan?->name ?? '—' }}</div>
                <div class="muted">Siklus {{ $cycle }}</div>
            </td>
            <td class="muted">
                {{ $date($subscription->starts_at) }}<br>s/d {{ $date($subscription->expires_at) }}
            </td>
            <td class="right">{{ $money($subscription->amount) }}</td>
        </tr>
        <tr class="total">
            <td colspan="2" class="right">Total</td>
            <td class="right">{{ $money($subscription->amount) }}</td>
        </tr>
    </table>

    @yield('body')

    <div class="footer">
        Dokumen ini dibuat otomatis oleh sistem {{ $issuer['issuer_name'] }} dan sah tanpa tanda tangan.
    </div>
</body>
</html>
