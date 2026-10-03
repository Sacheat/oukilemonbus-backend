@props(['ticket'])
{{-- Résumé en deux lignes pour un agent ou un admin. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1;border-radius:13px;background:#f9fbff;">
  <tr><td style="padding:14px 18px;">
    <div style="font-size:12px;font-weight:700;color:#69778c;text-transform:uppercase;letter-spacing:.05em;">Réservation n° {{ $ticket->number }}</div>
    <div style="margin-top:4px;font-size:16px;font-weight:800;word-break:break-word;">{{ $ticket->summaryWhen }}</div>
    <div style="margin-top:4px;font-size:14px;color:#56657a;word-break:break-word;overflow-wrap:anywhere;">{{ $ticket->summaryRoute }}</div>
  </td></tr>
</table>
