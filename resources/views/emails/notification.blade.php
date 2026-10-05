{{--
    Notification opérationnelle (réservation, demande, document…). Variables préparées par OperationalNotification via
    EmailPresenter : $pageTitle, $space, $commune, $priorityHigh, $tone [label, bg, fg], $heading, $greeting, $body,
    $reason, $ticket (TripTicket|null), $ticketFull, $button [url, label]|null, $documentLink [url, label, attached]|null.
--}}
@extends('emails.layouts.base')

@section('banner')
@if ($priorityHigh)
<tr><td class="px" style="background:#fdecea;padding:10px 28px;border-left:1px solid #e3e9f1;border-right:1px solid #e3e9f1;font-size:13px;font-weight:700;color:#b42318;">À traiter rapidement</td></tr>
@endif
@endsection

@section('content')
<x-emails::chip :label="$tone['label']" :bg="$tone['bg']" :fg="$tone['fg']" />
<h1 class="h1" style="margin:14px 0 0;font-size:24px;line-height:1.25;font-weight:800;color:#172b4d;">{{ $heading }}</h1>
<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $greeting }}</p>
<p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#172b4d;word-break:break-word;overflow-wrap:anywhere;">{{ $body }}</p>
@if ($reason)
<x-emails::quote>{{ $reason }}</x-emails::quote>
@endif
@if ($ticket)
<div style="margin-top:22px;">
@if ($ticketFull)
<x-emails::trip-ticket :ticket="$ticket" />
@else
<x-emails::trip-summary :ticket="$ticket" />
@endif
</div>
@endif
@if ($button)
<p style="margin:24px 0 0;"><x-emails::button :url="$button['url']" :label="$button['label']" /></p>
@endif
@if ($documentLink)
<p style="margin:14px 0 0;font-size:14px;line-height:1.5;"><a href="{{ $documentLink['url'] }}" style="color:#2563eb;font-weight:600;text-decoration:none;">{{ $documentLink['label'] }}</a>@if ($documentLink['attached']) <span style="color:#69778c;">· aussi jointe à cet email</span>@endif</p>
@endif
@if ($button)
<x-emails::fallback-link :url="$button['url']" />
@endif
@endsection
