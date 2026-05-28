@extends('demo.layout', ['title' => 'Reservations'])

@section('content')
    <h1>Reservations</h1>
    <p class="lede">Actions directes sur les services backend : affectation, approbation, confirmation et annulation.</p>

    <form class="filters" method="get" action="{{ route('demo.reservations') }}">
        <label>
            Statut
            <select name="status">
                <option value="">Tous</option>
                @foreach (\App\Enums\ReservationStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <button class="primary" type="submit">Filtrer</button>
    </form>

    <section class="panel">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Trajet</th>
                <th>Organisation</th>
                <th>Statut</th>
                <th>Ressources</th>
                <th>Conflits</th>
                <th>Actions demo</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reservations as $reservation)
                <tr>
                    <td>#{{ $reservation->id }}</td>
                    <td>
                        <strong>{{ $reservation->trip_date?->format('d/m/Y') }}</strong><br>
                        {{ $reservation->departure_time?->format('H:i') }} - {{ $reservation->return_time?->format('H:i') }}<br>
                        <span class="muted">
                            {{ $reservation->pickupLocation?->city ?? 'Depart ?' }}
                            -> {{ $reservation->destinationLocation?->city ?? 'Destination ?' }}
                        </span>
                    </td>
                    <td>
                        {{ $reservation->organization?->name ?? '-' }}<br>
                        <span class="muted">{{ $reservation->requestedByUser?->email }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $reservation->status?->value === 'en_conflit' ? 'danger' : 'brand' }}">
                            {{ $reservation->status?->label() ?? $reservation->status }}
                        </span>
                    </td>
                    <td>
                        Chauffeur: {{ $reservation->driver?->user?->first_name ?? '-' }} {{ $reservation->driver?->user?->last_name ?? '' }}<br>
                        Vehicule: {{ $reservation->vehicle?->licence_plate ?? '-' }}
                    </td>
                    <td>
                        @forelse ($reservation->conflicts->where('is_resolved', false) as $conflict)
                            <span class="badge danger">{{ $conflict->type?->value ?? $conflict->type }}</span><br>
                            <span class="muted">{{ $conflict->message }}</span><br>
                        @empty
                            <span class="badge ok">Aucun</span>
                        @endforelse
                    </td>
                    <td>
                        <div class="actions">
                            <form method="post" action="{{ route('demo.reservations.auto-assign', $reservation) }}">
                                @csrf
                                <button type="submit">Auto</button>
                            </form>

                            <form class="actions" method="post" action="{{ route('demo.reservations.assign', $reservation) }}">
                                @csrf
                                <select name="driver_id" aria-label="Chauffeur">
                                    <option value="">Chauffeur</option>
                                    @foreach ($drivers as $driver)
                                        <option value="{{ $driver->id }}">{{ $driver->user?->first_name }} {{ $driver->user?->last_name }}</option>
                                    @endforeach
                                </select>
                                <select name="vehicle_id" aria-label="Vehicule">
                                    <option value="">Vehicule</option>
                                    @foreach ($vehicles as $vehicle)
                                        <option value="{{ $vehicle->id }}">{{ $vehicle->licence_plate }} ({{ $vehicle->capacity }})</option>
                                    @endforeach
                                </select>
                                <button type="submit">Affecter</button>
                            </form>

                            <form method="post" action="{{ route('demo.reservations.approve', $reservation) }}">
                                @csrf
                                <button class="primary" type="submit">Approuver</button>
                            </form>
                            <form method="post" action="{{ route('demo.reservations.confirm', $reservation) }}">
                                @csrf
                                <button class="primary" type="submit">Confirmer</button>
                            </form>
                            <form method="post" action="{{ route('demo.reservations.cancel', $reservation) }}">
                                @csrf
                                <button class="danger" type="submit">Annuler</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Aucune reservation disponible.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="pagination">{{ $reservations->links() }}</div>
    </section>
@endsection
