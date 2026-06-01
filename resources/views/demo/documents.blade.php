@extends('demo.layout', ['title' => 'Documents'])

@section('content')
    <h1>Documents</h1>
    <p class="lede">Generation de feuilles de route et consultation des documents produits par le backend.</p>

    <div class="grid">
        @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('generate', \App\Models\Document::class))
            <section class="panel span-6">
                <h2>Generer une feuille de route periode</h2>
                <form class="filters" method="post" action="{{ route('demo.documents.route-sheets') }}">
                    @csrf
                    <label>
                        Debut
                        <input type="date" name="start_date" value="{{ now()->toDateString() }}">
                    </label>
                    <label>
                        Fin
                        <input type="date" name="end_date" value="{{ now()->addDays(7)->toDateString() }}">
                    </label>
                    <button class="primary" type="submit">Generer</button>
                </form>
            </section>

            <section class="panel span-6">
                <h2>Generer une feuille journaliere par vehicule</h2>
                <form class="filters" method="post" action="{{ route('demo.documents.route-sheets') }}">
                    @csrf
                    <label>
                        Date
                        <input type="date" name="service_date" value="{{ now()->toDateString() }}">
                    </label>
                    <label>
                        Vehicule
                        <select name="vehicle_id">
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}">{{ $vehicle->licence_plate }} ({{ $vehicle->capacity }})</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="primary" type="submit">Generer</button>
                </form>
            </section>
        @endif

        <section class="panel span-12">
            <h2>Documents generes</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Fichier</th>
                    <th>Contexte</th>
                    <th>Etat</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($documents as $document)
                    <tr>
                        <td>#{{ $document->id }}</td>
                        <td>{{ $document->document_type?->label() ?? $document->document_type }}</td>
                        <td>
                            <strong>{{ $document->file_name }}</strong><br>
                            <span class="muted">v{{ $document->version }} - {{ number_format(($document->file_size ?? 0) / 1024, 1) }} Ko</span>
                        </td>
                        <td>
                            Reservation: {{ $document->reservation_id ?? '-' }}<br>
                            <span class="muted">{{ $document->generated_at?->format('d/m/Y H:i') }}</span>
                        </td>
                        <td>
                            <span class="badge {{ $document->is_available ? 'ok' : 'danger' }}">
                                {{ $document->is_available ? 'Disponible' : 'Indisponible' }}
                            </span>
                        </td>
                        <td>
                            @if ($document->is_available && \Illuminate\Support\Facades\Gate::forUser($actor)->allows('download', $document))
                                <a class="button" href="{{ route('demo.documents.download', $document) }}">Telecharger</a>
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucun document.</td></tr>
                @endforelse
                </tbody>
            </table>

            <div class="pagination">{{ $documents->links() }}</div>
        </section>
    </div>
@endsection
