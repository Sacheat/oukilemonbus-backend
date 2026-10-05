@props(['logo', 'space' => null])
<tr><td class="px" style="background:#172b4d;border-radius:16px 16px 0 0;padding:18px 28px;">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td width="32" height="32" style="width:32px;height:32px;background:#f4c95d;border-radius:9px;vertical-align:middle;">
      <img src="{{ $logo }}" width="32" height="32" alt="OuKiLéMonBus" style="display:block;width:32px;height:32px;border:0;">
    </td>
    <td style="padding-left:10px;color:#ffffff;font-size:18px;font-weight:800;letter-spacing:-.01em;">OUKILEMONBUS</td>
    @if ($space)
      <td style="padding-left:12px;color:#9fb3d0;font-size:13px;font-weight:600;">{{ $space }}</td>
    @endif
  </tr></table>
</td></tr>
