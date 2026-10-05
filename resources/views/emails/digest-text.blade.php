OUKILEMONBUS

{!! $surtitle !!}
{!! $heading !!}

{!! $intro !!}
@foreach ($items as $item)

- {!! $item['title'] !!}@if ($item['time']) ({!! $item['time'] !!})@endif

  {!! $item['message'] !!}
@if ($item['link'])
  {!! $item['link']['label'] !!} : {!! $item['link']['url'] !!}
@endif
@endforeach

{!! $button['label'] !!} : {!! $button['url'] !!}

--
Service du bus communal · Commune de {!! $commune !!}
Cet email est envoyé automatiquement, merci de ne pas y répondre.
