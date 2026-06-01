@extends('demo.layout', ['title' => 'Demandes'])

@section('content')
    <h1>Demandes</h1>
    <p class="lede">Demandes de creation d'organisation et de rattachement, filtrees selon le role.</p>

    <div class="grid">
        @if ($actor->can('org-registration-requests.create'))
            <section class="panel span-6">
                <h2>Demander une organisation</h2>
                <form class="form-grid" method="post" action="{{ route('demo.requests.organizations.store') }}">
                    @csrf
                    <label>Nom <input name="organization_name" value="{{ old('organization_name') }}" required></label>
                    <label>
                        Type
                        <select name="organization_type" required>
                            @foreach ($organizationTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Email officiel <input type="email" name="official_email" value="{{ old('official_email', $actor->email) }}" required></label>
                    <label>Telephone <input name="phone" value="{{ old('phone') }}"></label>
                    <label>
                        Lieu
                        <select name="location_id">
                            <option value="">Aucun</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->formatted_address ?? $location->city }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Contact <input name="contact_person_name" value="{{ old('contact_person_name') }}"></label>
                    <label class="full">Commentaire <textarea name="comment">{{ old('comment') }}</textarea></label>
                    <div class="actions full"><button class="primary" type="submit">Envoyer</button></div>
                </form>
            </section>
        @endif

        @if ($actor->can('user-org-requests.create'))
            <section class="panel span-6">
                <h2>Demander un rattachement</h2>
                <form class="form-grid" method="post" action="{{ route('demo.requests.user-organizations.store') }}">
                    @csrf
                    <label>
                        Organisation
                        <select name="organization_id" required>
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        Role demande
                        <select name="requested_role" required>
                            @foreach ($requestedRoles as $requestedRole)
                                <option value="{{ $requestedRole }}">{{ $requestedRole }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="full">Justification <textarea name="justification">{{ old('justification', 'Demande de demonstration') }}</textarea></label>
                    <div class="actions full"><button class="primary" type="submit">Envoyer</button></div>
                </form>
            </section>
        @endif

        <section class="panel span-12">
            <h2>Demandes d'organisation</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Organisation</th>
                    <th>Demandeur</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($orgRequests as $orgRequest)
                    <tr>
                        <td>#{{ $orgRequest->id }}</td>
                        <td>{{ $orgRequest->organization_name }}<br><span class="muted">{{ $orgRequest->organization_type?->label() }}</span></td>
                        <td>{{ $orgRequest->requestedByUser?->email }}</td>
                        <td><span class="badge brand">{{ $orgRequest->status?->label() }}</span></td>
                        <td>
                            <div class="actions">
                                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('approve', $orgRequest))
                                    <form method="post" action="{{ route('demo.requests.organizations.approve', $orgRequest) }}">@csrf<button class="primary" type="submit">Approuver</button></form>
                                @endif
                                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('reject', $orgRequest))
                                    <form class="actions" method="post" action="{{ route('demo.requests.organizations.reject', $orgRequest) }}">
                                        @csrf
                                        <input name="rejection_reason" placeholder="Motif" required>
                                        <button class="danger" type="submit">Refuser</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune demande.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="pagination">{{ $orgRequests->links() }}</div>
        </section>

        <section class="panel span-12">
            <h2>Demandes de rattachement</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Organisation</th>
                    <th>Demandeur</th>
                    <th>Role</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($userOrgRequests as $userOrgRequest)
                    <tr>
                        <td>#{{ $userOrgRequest->getKey() }}</td>
                        <td>{{ $userOrgRequest->organization?->name }}</td>
                        <td>{{ $userOrgRequest->requestedByUser?->email }}</td>
                        <td>{{ $userOrgRequest->requested_role }}</td>
                        <td><span class="badge brand">{{ $userOrgRequest->status?->label() }}</span></td>
                        <td>
                            <div class="actions">
                                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('approve', $userOrgRequest))
                                    <form method="post" action="{{ route('demo.requests.user-organizations.approve', $userOrgRequest) }}">@csrf<button class="primary" type="submit">Approuver</button></form>
                                @endif
                                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('reject', $userOrgRequest))
                                    <form class="actions" method="post" action="{{ route('demo.requests.user-organizations.reject', $userOrgRequest) }}">
                                        @csrf
                                        <input name="rejection_reason" placeholder="Motif" required>
                                        <button class="danger" type="submit">Refuser</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucune demande.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="pagination">{{ $userOrgRequests->links() }}</div>
        </section>
    </div>
@endsection
