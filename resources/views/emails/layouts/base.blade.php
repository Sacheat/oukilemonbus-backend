{{--
    Squelette commun des emails OuKiLéMonBus. Mise en page en tableaux et styles en ligne (clients mail) ; le <style> ne
    sert qu'aux media queries (classes outer, px, h1, btn, leg-time), jamais au rendu de base : l'email reste correct
    quand elles sont ignorées. Variables : $pageTitle, $commune, $space (facultatif : « Gestion »).
    Le logo est une image PNG jointe en ligne ($message->embed) : les SVG ne s'affichent pas dans Gmail ni Outlook.
--}}
@php
    $logo = isset($message) ? $message->embed(resource_path('images/email/logo.png')) : 'cid:logo';
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $pageTitle ?? 'OuKiLéMonBus' }}</title>
<style>
@media only screen and (max-width: 480px) {
  .outer { padding: 8px !important; }
  .px { padding-left: 18px !important; padding-right: 18px !important; }
  .h1 { font-size: 21px !important; }
  .btn { display: block !important; text-align: center !important; padding: 14px 18px !important; }
  .leg-time { width: 52px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;font-family:Inter,'Segoe UI',Arial,sans-serif;color:#172b4d;">
  <tr><td align="center" class="outer" style="padding:28px 12px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">
      <x-emails::header :space="$space ?? null" :logo="$logo" />
      @yield('banner')
      <tr><td class="px" style="background:#ffffff;padding:32px 28px 28px;border:1px solid #e3e9f1;border-top:0;border-radius:0 0 16px 16px;">
        @yield('content')
      </td></tr>
      <x-emails::footer :commune="$commune" />
    </table>
  </td></tr>
</table>
</body>
</html>
