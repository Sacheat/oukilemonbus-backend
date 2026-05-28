@extends('demo.layout', ['title' => 'Dashboard'])

@section('content')
    <h1>Tableau de bord</h1>
    <p class="lede">Vue courte pour presenter l'etat du backend et les donnees disponibles.</p>

    <div class="grid">
        @foreach ($stats as $label => $value)
            <section class="panel span-3">
                <div class="metric">{{ str_replace('_', ' ', $label) }}</div>
                <div class="metric-value">{{ $value }}</div>
            </section>
        @endforeach

        <section class="panel span-8">
            <h2>Dernieres reservations</h2>
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
            <h2>Parcours conseille pour la demo</h2>
            <p class="lede">
                1. Montrer les compteurs et les tests backend.
                2. Ouvrir les reservations, affecter un chauffeur/vehicule, approuver puis confirmer.
                3. Montrer le planning mis a jour.
                4. Generer une feuille de route.
                5. Afficher les notifications creees par les actions metier.
            </p>
        </section>
    </div>
@endsection
