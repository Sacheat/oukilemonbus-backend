{{-- Réinitialisation du mot de passe. Variables : $pageTitle, $commune, $greeting, $intro, $url, $minutes. --}}
@extends('emails.layouts.base')

@section('content')
<h1 class="h1" style="margin:0;font-size:24px;line-height:1.25;font-weight:800;color:#172b4d;">Réinitialisez votre mot de passe</h1>
<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $greeting }}</p>
<p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $intro }}</p>
<p style="margin:24px 0 0;"><x-emails::button :url="$url" label="Choisir un nouveau mot de passe" /></p>
<x-emails::notice>Ce lien est valable <strong>{{ $minutes }} minutes</strong>.</x-emails::notice>
<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#69778c;">Vous n’êtes pas à l’origine de cette demande ? Ignorez cet email : votre mot de passe reste inchangé.</p>
<x-emails::fallback-link :url="$url" />
@endsection
