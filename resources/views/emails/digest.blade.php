{{--
    Récapitulatif de notifications. Variables : $pageTitle, $commune, $surtitle, $heading, $intro, $items
    (liste de [dot, title, time, message, link [url, label]|null]), $button [url, label].
--}}
@extends('emails.layouts.base')

@section('content')
<div style="font-size:13px;font-weight:700;color:#69778c;text-transform:uppercase;letter-spacing:.05em;">{{ $surtitle }}</div>
<h1 class="h1" style="margin:6px 0 0;font-size:24px;line-height:1.25;font-weight:800;color:#172b4d;">{{ $heading }}</h1>
<p style="margin:12px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $intro }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;border:1px solid #e3e9f1;border-radius:13px;">
  @foreach ($items as $item)
  <tr><td style="padding:14px 16px;@if (! $loop->last)border-bottom:1px solid #e3e9f1;@endif">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="10" style="width:10px;vertical-align:top;padding-top:6px;"><div style="width:8px;height:8px;border-radius:8px;background:{{ $item['dot'] }};font-size:0;line-height:0;">&nbsp;</div></td>
      <td style="padding-left:10px;font-size:14px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere;">
        <strong>{{ $item['title'] }}</strong>@if ($item['time']) <span style="color:#69778c;font-size:12px;">· {{ $item['time'] }}</span>@endif<br>
        <span style="color:#56657a;">{{ $item['message'] }}</span>
        @if ($item['link'])<br><a href="{{ $item['link']['url'] }}" style="color:#2563eb;font-weight:600;font-size:13px;text-decoration:none;">{{ $item['link']['label'] }}</a>@endif
      </td>
    </tr></table>
  </td></tr>
  @endforeach
</table>
<p style="margin:24px 0 0;"><x-emails::button :url="$button['url']" :label="$button['label']" /></p>
@endsection
