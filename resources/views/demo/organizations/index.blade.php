@extends('demo.layout', ['title' => 'Organisations'])

@section('content')
    <h1>Organisations</h1>
    <p class="lede">Organisations visibles pour l'acteur courant.</p>

    <section class="panel">
        <table>
            <thead>
            <tr>
                <th>Nom</th>
                <th>Type</th>
                <th>Contact</th>
                <th>Lieu</th>
                <th>Membres</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($organizations as $organization)
                <tr>
                    <td>
                        <strong>{{ $organization->name }}</strong><br>
                        @if ($organization->is_priority)
                            <span class="badge ok">Prioritaire</span>
                        @endif
                    </td>
                    <td>{{ $organization->type?->label() ?? $organization->type }}</td>
                    <td>
                        {{ $organization->official_email }}<br>
                        <span class="muted">{{ $organization->phone_contact }} {{ $organization->name_contact }}</span>
                    </td>
                    <td>{{ $organization->location?->formatted_address ?? $organization->location?->city ?? '-' }}</td>
                    <td>{{ $organization->users->count() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Aucune organisation visible.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="pagination">{{ $organizations->links() }}</div>
    </section>
@endsection
