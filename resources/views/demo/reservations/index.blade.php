@extends('demo.layout', ['title' => 'Reservations'])

@section('content')
    <div class="split">
        <div>
            <h1>Reservations</h1>
            <p class="lede">Liste filtree comme le ferait l'API pour l'acteur courant.</p>
        </div>
        @if ($actor->can('reservations.create'))
            <a class="button primary" href="{{ route('demo.reservations.create') }}">Nouvelle reservation</a>
        @endif
    </div>

    <form class="filters" method="get" action="{{ route('demo.reservations') }}">
        <label>
            Statut
            <select name="status">
                <option value="">Tous</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Organisation
            <select name="organization_id">
                <option value="">Toutes</option>
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected((string) request('organization_id') === (string) $organization->id)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Depuis <input type="date" name="date_from" value="{{ request('date_from') }}"></label>
        <label>Jusqu'a <input type="date" name="date_to" value="{{ request('date_to') }}"></label>
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
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reservations as $reservation)
                <tr>
                    <td>#{{ $reservation->id }}</td>
                    <td>
                        <strong>{{ $reservation->trip_date?->format('d/m/Y') }}</strong><br>
                        {{ $reservation->departure_time?->format('H:i') }} - {{ $reservation->return_time?->format('H:i') }}<br>
                        <span class="muted">{{ $reservation->pickupLocation?->city ?? '-' }} -> {{ $reservation->destinationLocation?->city ?? '-' }}</span><br>
                        <span class="muted">{{ $reservation->trip_purpose }}</span>
                    </td>
                    <td>
                        {{ $reservation->organization?->name ?? '-' }}<br>
                        <span class="muted">{{ $reservation->requestedByUser?->email }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $reservation->status?->value === 'en_conflit' ? 'danger' : 'brand' }}">
                            {{ $reservation->status?->label() ?? $reservation->status }}
                        </span>
                        @if ($reservation->conflicts->where('is_resolved', false)->isNotEmpty())
                            <br><span class="badge danger">{{ $reservation->conflicts->where('is_resolved', false)->count() }} conflit(s)</span>
                        @endif
                    </td>
                    <td>
                        Chauffeur: {{ $reservation->driver?->user?->first_name ?? '-' }} {{ $reservation->driver?->user?->last_name ?? '' }}<br>
                        Vehicule: {{ $reservation->vehicle?->licence_plate ?? '-' }}
                    </td>
                    <td>
                        <div class="actions">
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('update', $reservation))
                                <a class="button" href="{{ route('demo.reservations.edit', $reservation) }}">Modifier</a>
                            @endif
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('assign', $reservation))
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
                                    <input type="hidden" name="reason" value="Affectation depuis la demo backend">
                                    <button type="submit">Affecter</button>
                                </form>
                            @endif
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('approve', $reservation))
                                <form method="post" action="{{ route('demo.reservations.approve', $reservation) }}">
                                    @csrf
                                    <button class="primary" type="submit">Approuver</button>
                                </form>
                            @endif
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('reject', $reservation))
                                <form class="actions" method="post" action="{{ route('demo.reservations.reject', $reservation) }}">
                                    @csrf
                                    <input name="reason" placeholder="Motif refus" required>
                                    <button class="danger" type="submit">Refuser</button>
                                </form>
                            @endif
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('confirm', $reservation))
                                <form method="post" action="{{ route('demo.reservations.confirm', $reservation) }}">
                                    @csrf
                                    <button class="primary" type="submit">Confirmer</button>
                                </form>
                            @endif
                            @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('cancel', $reservation))
                                <form class="actions" method="post" action="{{ route('demo.reservations.cancel', $reservation) }}">
                                    @csrf
                                    <input name="reason" placeholder="Motif annulation" value="Annulation depuis la demo" required>
                                    <button class="danger" type="submit">Annuler</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucune reservation visible.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="pagination">{{ $reservations->links() }}</div>
    </section>
@endsection
