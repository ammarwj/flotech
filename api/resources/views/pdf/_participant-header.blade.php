{{--
  Issuer and bill-to blocks shared by the participant invoice and receipt.

  The issuer is the ORGANIZER, not the platform: a ticket sale is their sale and
  the money lands in their wallet. `organizations` holds only a name, an email
  and a phone — there is no address or NPWP column to print, so this block is
  deliberately shorter than the platform's.
--}}
@section('issuer')
    <div class="issuer-name">{{ $org?->name ?? '—' }}</div>
    @if ($org?->contact_email)
        <div class="muted">{{ $org->contact_email }}</div>
    @endif
    @if ($org?->contact_phone)
        <div class="muted">{{ $org->contact_phone }}</div>
    @endif
@endsection

@section('billed-to')
    <div style="font-weight: bold; margin-top: 4px;">{{ $payerName }}</div>
    @foreach ($payerLines as $line)
        <div class="muted">{{ $line }}</div>
    @endforeach
@endsection

@section('footer-note')
    Diterbitkan oleh {{ $org?->name ?? 'penyelenggara' }}, diproses melalui {{ $issuer['issuer_name'] }}.
@endsection
