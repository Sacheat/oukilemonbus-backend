OUKILEMONBUS{{ $space ? ' · '.$space : '' }}
@if ($priorityHigh)

!! À TRAITER RAPIDEMENT !!
@endif

[{!! $tone['label'] !!}]
{!! $heading !!}

{!! $greeting !!}

{!! $body !!}
@if ($reason)

Motif : {!! $reason !!}
@endif
@if ($ticket)

Réservation n° {!! $ticket->number !!}@if ($ticket->organization) · {!! $ticket->organization !!}@endif

@if ($ticketFull)
{!! $ticket->dateLong !!}
@foreach ($ticket->legs as $leg)
{!! $leg['time'] !!}  {!! $leg['label'] !!} : {!! $leg['place'] !!}
@endforeach
{!! implode(' · ', $ticket->chips) !!}
@else
{!! $ticket->summaryWhen !!}
{!! $ticket->summaryRoute !!}
@endif
@endif
@if ($button)

{!! $button['label'] !!} : {!! $button['url'] !!}
@endif
@if ($documentLink)

{!! $documentLink['label'] !!} : {!! $documentLink['url'] !!}@if ($documentLink['attached']) (aussi jointe à cet email)@endif

@endif

--
Service du bus communal · Commune de {!! $commune !!}
Cet email est envoyé automatiquement, merci de ne pas y répondre.
