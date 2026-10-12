{{-- Avertissement avant anonymisation d'un compte inactif. Variables : $pageTitle, $commune, $greeting, $years, $date, $url. --}}
@extends('emails.layouts.base')

@section('content')
<h1 class="h1" style="margin:0;font-size:24px;line-height:1.25;font-weight:800;color:#172b4d;">Votre compte va être supprimé</h1>
<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">{{ $greeting }}</p>
<p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#172b4d;">Vous n’avez pas utilisé votre compte OuKiLéMonBus (bus communal de {{ $commune }}) depuis près de {{ $years }} ans. Pour protéger vos données, il sera supprimé le <strong>{{ $date }}</strong>.</p>
<p style="margin:24px 0 0;"><x-emails::button :url="$url" label="Me connecter" /></p>
<x-emails::notice>Pour garder votre compte, il suffit de vous connecter avant cette date.</x-emails::notice>
<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#69778c;">Sans connexion, vos nom, email et téléphone seront effacés. L’historique des trajets reste, sans lien avec vous.</p>
<x-emails::fallback-link :url="$url" />
@endsection
