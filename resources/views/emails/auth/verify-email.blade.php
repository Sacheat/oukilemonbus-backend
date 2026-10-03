{{-- Vérification de l'email. Variables : $pageTitle, $commune, $greeting, $welcome, $url, $minutes. --}}
@extends('emails.layouts.base')

@section('content')
<h1 class="h1" style="margin:0;font-size:24px;line-height:1.25;font-weight:800;color:#172b4d;">Confirmez votre adresse email</h1>
<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $greeting }}</p>
<p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $welcome }}</p>
<p style="margin:24px 0 0;"><x-emails::button :url="$url" label="Confirmer mon adresse" /></p>
<x-emails::notice>Ce lien est valable <strong>{{ $minutes }} minutes</strong>. Passé ce délai, connectez-vous et demandez un nouveau lien.</x-emails::notice>
<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#69778c;">Vous n’avez pas créé de compte ? Ignorez cet email, aucun compte ne sera activé.</p>
<x-emails::fallback-link :url="$url" />
@endsection
