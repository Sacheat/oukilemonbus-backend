@extends('demo.layout', ['title' => 'Dashboard'])

@section('content')
    <h1>Tableau de bord</h1>
    <p class="lede">Vue minimale du vrai perimetre fonctionnel disponible pour l'acteur selectionne.</p>

    <div class="grid">
        @foreach ($stats as $label => $value)
            <section class="panel span-3">
                <div class="metric">{{ str_replace('_', ' ', $label) }}</div>
                <div class="metric-value">{{ $value }}</div>
            </section>
        @endforeach

        <section class="panel span-4">
            <h2>Acteur courant</h2>
            <p><strong>{{ trim($actor->first_name.' '.$actor->last_name) ?: $actor->email }}</strong></p>
            <p class="muted">{{ $actor->email }}</p>
            <div class="actions">
                @foreach ($actorRoles as $role)
                    <span class="badge brand">{{ $role }}</span>
                @endforeach
            </div>
            <p class="muted">
                Organisations:
                {{ $actor->organizations->pluck('name')->join(', ') ?: 'aucune' }}
            </p>
        </section>

        <section class="panel span-8">
            <h2>Actions disponibles</h2>
            <div class="actions">
                @forelse ($availableActions as $action)
                    <span class="badge ok">{{ $action }}</span>
                @empty
                    <span class="muted">Aucune action metier directe pour cet acteur.</span>
                @endforelse
            </div>
        </section>

        <section class="panel span-8">
            <div class="split">
                <h2>Dernieres reservations visibles</h2>
                @if ($actor->can('reservations.create'))
                    <a class="button primary" href="{{ route('demo.reservations.create') }}">Nouvelle reservation</a>
                @endif
            </div>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Organisation</th>
                    <th>Statut</th>
                    <th>Affectation</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($latestReservations as $reservation)
                    <tr>
                        <td>#{{ $reservation->id }}</td>
                        <td>{{ $reservation->trip_date?->format('d/m/Y') }} {{ $reservation->departure_time?->format('H:i') }}</td>
                        <td>{{ $reservation->organization?->name ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $reservation->conflicts->where('is_resolved', false)->isNotEmpty() ? 'danger' : 'brand' }}">
                                {{ $reservation->status?->label() ?? $reservation->status }}
                            </span>
                        </td>
                        <td>
                            {{ $reservation->driver?->user?->first_name ?? 'Chauffeur ?' }}
                            /
                            {{ $reservation->vehicle?->licence_plate ?? 'Vehicule ?' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune reservation.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel span-4">
            <h2>Notifications de l'acteur</h2>
            <table>
                <tbody>
                @forelse ($latestNotifications as $notification)
                    <tr>
                        <td>
                            <strong>{{ data_get($notification->data, 'title', 'Notification') }}</strong><br>
                            <span class="muted">{{ data_get($notification->data, 'message') }}</span>
                        </td>
                        <td>
                            <span class="badge {{ $notification->read_at ? 'ok' : 'warning' }}">
                                {{ $notification->read_at ? 'Lue' : 'Non lue' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td class="muted">Aucune notification.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel span-12">
            <h2>Compteurs globaux</h2>
            <div class="actions">
                @foreach ($globalStats as $label => $value)
                    <span class="badge">{{ str_replace('_', ' ', $label) }}: {{ $value }}</span>
                @endforeach
            </div>
        </section>
    </div>
@endsection
