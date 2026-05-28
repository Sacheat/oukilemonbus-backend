@extends('demo.layout', ['title' => 'Demandes'])

@section('content')
    <h1>Demandes administratives</h1>
    <p class="lede">Validation des demandes de creation d'organisation et des demandes de rattachement.</p>

    <div class="grid">
        <section class="panel span-6">
            <h2>Creation d'organisation</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Demande</th>
                    <th>Score</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($orgRequests as $requestItem)
                    <tr>
                        <td>#{{ $requestItem->id }}</td>
                        <td>
                            <strong>{{ $requestItem->organization_name }}</strong><br>
                            <span class="muted">{{ $requestItem->requestedByUser?->email }}</span>
                        </td>
                        <td>{{ $requestItem->confidence_note ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $requestItem->status?->value === 'en_attente' ? 'warning' : ($requestItem->status?->value === 'approuve' ? 'ok' : 'danger') }}">
                                {{ $requestItem->status?->label() }}
                            </span>
                        </td>
                        <td>
                            @if ($requestItem->status?->value === 'en_attente')
                                <div class="actions">
                                    <form method="post" action="{{ route('demo.requests.organizations.approve', $requestItem) }}">
                                        @csrf
                                        <button class="primary" type="submit">Approuver</button>
                                    </form>
                                    <form method="post" action="{{ route('demo.requests.organizations.reject', $requestItem) }}">
                                        @csrf
                                        <button class="danger" type="submit">Refuser</button>
                                    </form>
                                </div>
                            @else
                                <span class="muted">Traitee</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune demande.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel span-6">
            <h2>Rattachement utilisateur</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Demande</th>
                    <th>Score</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($userOrgRequests as $requestItem)
                    <tr>
                        <td>#{{ $requestItem->request_id }}</td>
                        <td>
                            <strong>{{ $requestItem->requestedByUser?->email }}</strong><br>
                            <span class="muted">{{ $requestItem->organization?->name }} - {{ $requestItem->requested_role }}</span>
                        </td>
                        <td>{{ $requestItem->confidence_note ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $requestItem->status?->value === 'en_attente' ? 'warning' : ($requestItem->status?->value === 'approuve' ? 'ok' : 'danger') }}">
                                {{ $requestItem->status?->label() }}
                            </span>
                        </td>
                        <td>
                            @if ($requestItem->status?->value === 'en_attente')
                                <div class="actions">
                                    <form method="post" action="{{ route('demo.requests.user-organizations.approve', $requestItem->request_id) }}">
                                        @csrf
                                        <button class="primary" type="submit">Approuver</button>
                                    </form>
                                    <form method="post" action="{{ route('demo.requests.user-organizations.reject', $requestItem->request_id) }}">
                                        @csrf
                                        <button class="danger" type="submit">Refuser</button>
                                    </form>
                                </div>
                            @else
                                <span class="muted">Traitee</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune demande.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
