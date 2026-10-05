// Journée de test « Ma journée » pour Chloe (chauffeur.test@bus-communal.local), créée directement en base.
// Trois missions confirmées aujourd'hui, calées sur l'heure actuelle, et une demain à 09:00.
$tz = config('reservations.local_timezone', 'Europe/Brussels');
$now = Carbon\CarbonImmutable::now($tz);
$driver = App\Models\Driver::where('licence_number', 'DRV-SEED-001')->firstOrFail(); // Chloe
$vehicle = App\Models\Vehicle::whereIn('licence_plate', ['BUS-SEED-002', '1-VJX-649'])->orderBy('id')->firstOrFail();
$organization = App\Models\Organization::where('type', 'ecole_communale')->orderBy('id')->firstOrFail();
$pickup = App\Models\Location::where('place_id', 'seed-dinant-pickup')->firstOrFail();
$destination = App\Models\Location::where('place_id', 'seed-dinant-destination')->firstOrFail();
$requester = App\Models\User::where('email', 'user.test@bus-communal.local')->firstOrFail();
$quarter = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 15) * 15);
$slots = [
    [$quarter->subMinutes(15), 'A (départ possible tout de suite)'],
    [$quarter->addMinutes(90), 'B (départ possible 1 h avant)'],
    [$quarter->addMinutes(210), 'C (plus tard)'],
    [$now->addDay()->setTime(9, 0), 'demain'],
];
$ids = [];
foreach ($slots as [$start, $label]) {
    $ids[] = App\Models\Reservation::create([
        'requested_by_user_id' => $requester->id,
        'organization_id' => $organization->id,
        'pickup_location_id' => $pickup->id,
        'destination_location_id' => $destination->id,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'trip_date' => $start->toDateString(),
        'departure_time' => $start->format('H:i:s'),
        'return_time' => $start->addHour()->format('H:i:s'),
        'nb_children' => 18,
        'nb_adults' => 2,
        'distance_km' => 12.5,
        'estimated_duration_min' => 20,
        'trip_purpose' => 'TEST Ma journée - '.$label,
        'status' => 'confirme',
        'approval_type' => 'auto',
    ])->id;
}
echo 'Réservations de test : '.implode(', ', $ids).PHP_EOL;
