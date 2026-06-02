@extends('demo.layout', ['title' => 'Mes missions'])

@section('content')
    <h1>Mes missions</h1>
    <p class="lede">Trajets assignes a ce chauffeur, tels que le backend les retourne via <code>ReservationService</code>.</p>

    <div class="grid">
        <section class="panel span-4">
            <h2>Profil chauffeur</h2>
            <div class="stack">
                <div>
                    <div class="metric">Chauffeur</div>
                    <strong>{{ trim($actor->first_name.' '.$actor->last_name) ?: $actor->email }}</strong>
                </div>
                <div>
                    <div class="metric">Licence</div>
                    <span>{{ $driver->licence_number ?? '-' }}</span><br>
                    <span class="muted">Expire le {{ $driver->licence_expiry_date?->format('d/m/Y') ?? '-' }}</span>
                </div>
                <div>
                    <div class="metric">Etat</div>
                    <span class="badge {{ $driver->is_active ? 'ok' : 'danger' }}">
                        {{ $driver->is_active ? 'Actif' : 'Inactif' }}
                    </span>
                </div>
                <div>
                    <div class="metric">Trajets a venir</div>
                    <div class="metric-value">{{ $upcomingReservations->count() }}</div>
                </div>
            </div>
        </section>

        <section class="panel span-8">
            <h2>Prochains trajets</h2>
            <table>
                <thead>
                <tr>
                    <th>Date / Horaire</th>
                    <th>Trajet</th>
                    <th>Organisation</th>
                    <th>Vehicule</th>
                    <th>Passagers</th>
                    <th>Statut</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($upcomingReservations as $reservation)
                    @php
                        $isToday = $reservation->trip_date?->isToday();
                    @endphp
                    <tr>
                        <td>
                            <strong class="{{ $isToday ? '' : '' }}">
                                {{ $reservation->trip_date?->format('d/m/Y') }}
                                @if ($isToday)
                                    <span class="badge brand">Aujourd'hui</span>
                                @endif
                            </strong><br>
                            {{ $reservation->departure_time?->format('H:i') }}
                            &rarr;
                            {{ $reservation->return_time?->format('H:i') }}
                        </td>
                        <td>
                            <strong>{{ $reservation->pickupLocation?->city ?? '-' }}</strong><br>
                            <span class="muted">&darr; {{ $reservation->destinationLocation?->city ?? '-' }}</span><br>
                            <span class="muted">{{ $reservation->trip_purpose }}</span>
                        </td>
                        <td>{{ $reservation->organization?->name ?? '-' }}</td>
                        <td>
                            @if ($reservation->vehicle)
                                <strong>{{ $reservation->vehicle->licence_plate }}</strong><br>
                                <span class="muted">{{ $reservation->vehicle->capacity }} places</span>
                            @else
                                <span class="badge danger">Non affecte</span>
                            @endif
                        </td>
                        <td>
                            {{ $reservation->nb_children + $reservation->nb_adults }} pers.<br>
                            <span class="muted">{{ $reservation->nb_children }}e / {{ $reservation->nb_adults }}a</span>
                        </td>
                        <td>
                            <span class="badge brand">{{ $reservation->status?->label() ?? $reservation->status }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucun trajet a venir.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel span-12">
            <h2>Trajets recents</h2>
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Trajet</th>
                    <th>Organisation</th>
                    <th>Vehicule</th>
                    <th>Statut</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($pastReservations as $reservation)
                    <tr>
                        <td>
                            {{ $reservation->trip_date?->format('d/m/Y') }}<br>
                            <span class="muted">{{ $reservation->departure_time?->format('H:i') }} - {{ $reservation->return_time?->format('H:i') }}</span>
                        </td>
                        <td>
                            {{ $reservation->pickupLocation?->city ?? '-' }} &rarr; {{ $reservation->destinationLocation?->city ?? '-' }}<br>
                            <span class="muted">{{ $reservation->trip_purpose }}</span>
                        </td>
                        <td>{{ $reservation->organization?->name ?? '-' }}</td>
                        <td>{{ $reservation->vehicle?->licence_plate ?? '-' }}</td>
                        <td>
                            @php $status = $reservation->status?->value ?? $reservation->status; @endphp
                            <span class="badge {{ in_array($status, ['annule', 'refuse']) ? 'danger' : 'ok' }}">
                                {{ $reservation->status?->label() ?? $status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucun trajet passe.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection