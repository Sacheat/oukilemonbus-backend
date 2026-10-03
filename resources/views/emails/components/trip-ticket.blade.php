@props(['ticket'])
{{-- Billet complet (usager, chauffeur). Colonne d'heure de 56 px ; les étiquettes sont des inline-block qui passent à la ligne. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1;border-radius:13px;background:#f9fbff;">
  <tr><td style="padding:16px 18px 12px;border-bottom:1px dashed #c8d4e3;">
    <div style="font-size:12px;font-weight:700;color:#69778c;text-transform:uppercase;letter-spacing:.05em;word-break:break-word;">Réservation n° {{ $ticket->number }}@if ($ticket->organization) · {{ $ticket->organization }}@endif</div>
    <div style="margin-top:4px;font-size:18px;font-weight:800;color:#172b4d;">{{ $ticket->dateLong }}</div>
  </td></tr>
  <tr><td style="padding:14px 18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.45;">
      @foreach ($ticket->legs as $leg)
        @if (! $loop->first)
          <tr><td colspan="2" style="height:10px;line-height:10px;font-size:10px;">&nbsp;</td></tr>
        @endif
        <tr>
          <td class="leg-time" width="56" style="width:56px;vertical-align:top;font-weight:800;color:#172b4d;">{{ $leg['time'] }}</td>
          <td style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;"><span style="color:#69778c;">{{ $leg['label'] }}</span><br><strong>{{ $leg['place'] }}</strong></td>
        </tr>
      @endforeach
    </table>
  </td></tr>
  <tr><td style="padding:0 18px 10px;font-size:13px;line-height:1.4;">
    @foreach ($ticket->chips as $chip)<span style="display:inline-block;margin:0 6px 6px 0;background:#eef1f5;color:#56657a;padding:5px 10px;border-radius:8px;font-weight:600;">{{ $chip }}</span>@endforeach
  </td></tr>
</table>
