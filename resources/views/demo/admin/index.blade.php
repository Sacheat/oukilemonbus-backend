@extends('demo.layout', ['title' => 'Gestion'])

@section('content')
    <h1>Gestion</h1>
    <p class="lede">Vue interne minimale: lecture des ressources operationnelles accessibles a l'acteur.</p>

    <div class="grid">
        @if ($canViewUsers)
            <section class="panel span-12">
                <h2>Utilisateurs</h2>
                <table>
                    <thead><tr><th>ID</th><th>Nom</th><th>Email</th><th>Roles</th><th>Organisations</th><th>Etat</th></tr></thead>
                    <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>#{{ $user->id }}</td>
                            <td>{{ trim($user->first_name.' '.$user->last_name) ?: '-' }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->roles->pluck('name')->join(', ') }}</td>
                            <td>{{ $user->organizations->pluck('name')->join(', ') ?: '-' }}</td>
                            <td><span class="badge {{ $user->active ? 'ok' : 'danger' }}">{{ $user->active ? 'Actif' : 'Inactif' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($canViewDrivers)
            <section class="panel span-6">
                <h2>Chauffeurs</h2>
                <table>
                    <thead><tr><th>ID</th><th>Utilisateur</th><th>Licence</th><th>Etat</th></tr></thead>
                    <tbody>
                    @foreach ($drivers as $driver)
                        <tr>
                            <td>#{{ $driver->id }}</td>
                            <td>{{ $driver->user?->first_name }} {{ $driver->user?->last_name }}</td>
                            <td>{{ $driver->licence_number }}<br><span class="muted">{{ $driver->licence_expiry_date?->format('d/m/Y') }}</span></td>
                            <td><span class="badge {{ $driver->is_active ? 'ok' : 'danger' }}">{{ $driver->is_active ? 'Actif' : 'Inactif' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($canViewVehicles)
            <section class="panel span-6">
                <h2>Vehicules</h2>
                <table>
                    <thead><tr><th>ID</th><th>Plaque</th><th>Capacite</th><th>Etat</th></tr></thead>
                    <tbody>
                    @foreach ($vehicles as $vehicle)
                        <tr>
                            <td>#{{ $vehicle->id }}</td>
                            <td>{{ $vehicle->licence_plate }}</td>
                            <td>{{ $vehicle->capacity }}</td>
                            <td><span class="badge {{ $vehicle->is_active ? 'ok' : 'danger' }}">{{ $vehicle->is_active ? 'Actif' : 'Inactif' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($canViewUnavailabilities && $unavailabilities)
            <section class="panel span-12">
                <h2>Indisponibilites</h2>
                <table>
                    <thead><tr><th>Type</th><th>Ressource</th><th>Periode</th><th>Motif</th><th>Etat</th></tr></thead>
                    <tbody>
                    @forelse ($unavailabilities as $unavailability)
                        <tr>
                            <td>{{ $unavailability->reason_type?->value ?? $unavailability->reason_type }}</td>
                            <td>
                                {{ $unavailability->driver?->user?->first_name }} {{ $unavailability->driver?->user?->last_name }}
                                {{ $unavailability->vehicle?->licence_plate }}
                            </td>
                            <td>{{ $unavailability->start_datetime?->format('d/m/Y H:i') }} - {{ $unavailability->end_datetime?->format('d/m/Y H:i') }}</td>
                            <td>{{ $unavailability->reason }}</td>
                            <td><span class="badge {{ $unavailability->is_active ? 'warning' : 'ok' }}">{{ $unavailability->is_active ? 'Active' : 'Inactive' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Aucune indisponibilite.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="pagination">{{ $unavailabilities->links() }}</div>
            </section>
        @endif
    </div>
@endsection
