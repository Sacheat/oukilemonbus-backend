<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnavailabilityHistoryResource;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Consultation de l'historique des modifications d'indisponibilités (lecture seule, immuable).
 * `show` vérifie que l'entrée d'historique appartient bien à l'indisponibilité de la route.
 */
class UnavailabilityHistoryController extends Controller
{
    public function index(Unavailability $unavailability): AnonymousResourceCollection
    {
        return UnavailabilityHistoryResource::collection(
            $unavailability->history()
                ->with('changedByUser')
                ->orderBy('created_at', 'desc')
                ->paginate()
        );
    }

    public function show(Unavailability $unavailability, UnavailabilityHistory $history): UnavailabilityHistoryResource
    {
        // Empêche d'accéder à l'historique d'une autre indisponibilité via une URL forgée.
        abort_unless($history->unavailability_id === $unavailability->id, 404);

        return new UnavailabilityHistoryResource($history->load('changedByUser'));
    }
}
