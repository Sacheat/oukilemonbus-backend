@extends('demo.layout', ['title' => 'Modifier reservation'])

@section('content')
    <h1>Modifier reservation #{{ $reservation->id }}</h1>
    <p class="lede">Modification disponible uniquement si la policy l'autorise pour l'acteur courant.</p>

    <section class="panel">
        <form class="form-grid" method="post" action="{{ route('demo.reservations.update', $reservation) }}">
            @csrf
            @method('PATCH')
            <label>
                Depart
                <select name="pickup_location_id" required>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(old('pickup_location_id', $reservation->pickup_location_id) == $location->id)>{{ $location->formatted_address ?? $location->city }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Destination
                <select name="destination_location_id" required>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(old('destination_location_id', $reservation->destination_location_id) == $location->id)>{{ $location->formatted_address ?? $location->city }}</option>
                    @endforeach
                </select>
            </label>
            <label>Date <input type="date" name="trip_date" value="{{ old('trip_date', $reservation->trip_date?->toDateString()) }}" required></label>
            <label>Depart <input type="time" name="departure_time" value="{{ old('departure_time', $reservation->departure_time?->format('H:i')) }}" required></label>
            <label>Retour <input type="time" name="return_time" value="{{ old('return_time', $reservation->return_time?->format('H:i')) }}" required></label>
            <label>Enfants <input type="number" name="nb_children" min="0" value="{{ old('nb_children', $reservation->nb_children) }}" required></label>
            <label>Adultes <input type="number" name="nb_adults" min="1" value="{{ old('nb_adults', $reservation->nb_adults) }}" required></label>
            <label>
                Activite
                <select name="activity_type">
                    @foreach ($activityTypes as $activityType)
                        <option value="{{ $activityType->value }}" @selected(old('activity_type', $reservation->activity_type?->value) === $activityType->value)>{{ $activityType->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="full">
                Objet du trajet
                <textarea name="trip_purpose" required>{{ old('trip_purpose', $reservation->trip_purpose) }}</textarea>
            </label>
            <div class="actions full">
                <button class="primary" type="submit">Enregistrer</button>
                <a class="button" href="{{ route('demo.reservations') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
