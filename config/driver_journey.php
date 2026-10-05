<?php

/**
 * Journée du chauffeur (« Ma journée ») : valeurs par défaut, modifiables ensuite par commune (page Communes) dans
 * une étape suivante. Le catalogue des applications de navigation viendra s'ajouter ici.
 */
return [
    // Le premier « Départ » d'un trajet est possible à partir de N minutes avant l'heure prévue (jusqu'à la fin du jour)
    'departure_window_minutes' => (int) env('DRIVER_DEPARTURE_WINDOW_MINUTES', 60),
];
