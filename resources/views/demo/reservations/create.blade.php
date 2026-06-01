@extends('demo.layout', ['title' => 'Nouvelle reservation'])

@section('content')
    <h1>Nouvelle reservation</h1>
    <p class="lede">Formulaire minimal branche sur <code>ReservationService::create</code>.</p>

    <section class="panel">
        @if ($organizations->isEmpty())
            <p class="muted">Aucune organisation disponible pour cet acteur. Creer une demande de rattachement ou changer d'acteur.</p>
        @else
            <form class="form-grid" method="post" action="{{ route('demo.reservations.store') }}">
                @csrf
                <label>
                    Organisation
                    <select name="organization_id" required>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}" @selected(old('organization_id') == $organization->id)>{{ $organization->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Activite
                    <select name="activity_type">
                        @foreach ($activityTypes as $activityType)
                            <option value="{{ $activityType->value }}" @selected(old('activity_type', 'standard') === $activityType->value)>{{ $activityType->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Depart
                    <select name="pickup_location_id" required>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(old('pickup_location_id') == $location->id)>{{ $location->formatted_address ?? $location->city }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Destination
                    <select name="destination_location_id" required>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(old('destination_location_id') == $location->id)>{{ $location->formatted_address ?? $location->city }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Date <input type="date" name="trip_date" value="{{ old('trip_date', now()->addWeek()->toDateString()) }}" required></label>
                <label>Depart <input type="time" name="departure_time" value="{{ old('departure_time', '09:00') }}" required></label>
                <label>Retour <input type="time" name="return_time" value="{{ old('return_time', '11:00') }}" required></label>
                <label>Enfants <input type="number" name="nb_children" min="0" value="{{ old('nb_children', 10) }}" required></label>
                <label>Adultes <input type="number" name="nb_adults" min="1" value="{{ old('nb_adults', 1) }}" required></label>
                <label class="full">
                    Objet du trajet
                    <textarea name="trip_purpose" required>{{ old('trip_purpose', 'Sortie de demonstration') }}</textarea>
                </label>
                <div class="actions full">
                    <button class="primary" type="submit">Creer</button>
                    <a class="button" href="{{ route('demo.reservations') }}">Annuler</a>
                </div>
            </form>
        @endif
    </section>
@endsection
