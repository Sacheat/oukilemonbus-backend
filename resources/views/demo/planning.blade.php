@extends('demo.layout', ['title' => 'Planning'])

@section('content')
    <h1>Planning</h1>
    <p class="lede">Vue issue de <code>PlanningService</code>, avec les disponibilites visibles pour un acteur interne.</p>

    <form class="filters" method="get" action="{{ route('demo.planning') }}">
        <label>
            Debut
            <input type="date" name="start_date" value="{{ $startDate }}">
        </label>
        <label>
            Fin
            <input type="date" name="end_date" value="{{ $endDate }}">
        </label>
        <button class="primary" type="submit">Afficher</button>
    </form>

    <section class="panel">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Reservation</th>
                <th>Organisation</th>
                <th>Chauffeur</th>
                <th>Vehicule</th>
                <th>Disponibilite</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($items as $reservation)
                <tr>
                    <td>
                        <strong>{{ $reservation->trip_date?->format('d/m/Y') }}</strong><br>
                        {{ $reservation->departure_time?->format('H:i') }} - {{ $reservation->return_time?->format('H:i') }}
                    </td>
                    <td>
                        #{{ $reservation->id }}
                        <span class="badge brand">{{ $reservation->status?->label() }}</span><br>
                        <span class="muted">{{ $reservation->trip_purpose }}</span>
                    </td>
                    <td>{{ $reservation->organization?->name ?? '-' }}</td>
                    <td>{{ $reservation->driver?->user?->first_name ?? '-' }} {{ $reservation->driver?->user?->last_name ?? '' }}</td>
                    <td>{{ $reservation->vehicle?->licence_plate ?? '-' }}</td>
                    <td>
                        @php($availability = $reservation->getAttribute('planning_availability'))
                        @if ($availability)
                            <span class="badge {{ data_get($availability, 'driver.available') ? 'ok' : 'danger' }}">
                                Chauffeur {{ data_get($availability, 'driver.available') ? 'OK' : 'KO' }}
                            </span>
                            <span class="badge {{ data_get($availability, 'vehicle.available') ? 'ok' : 'danger' }}">
                                Vehicule {{ data_get($availability, 'vehicle.available') ? 'OK' : 'KO' }}
                            </span>
                        @else
                            <span class="muted">Non affiche pour cet acteur.</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucun trajet sur cette periode.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
@endsection
