<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnavailabilityHistoryResource;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;


/* *
 * Gère l'historique des modifications des indisponibilités.
 * Permet de consulter les changements passés, y compris les raisons des modifications et les utilisateurs qui les ont effectuées.
 */
class UnavailabilityHistoryController extends Controller
{
    public function index(Unavailability $unavailability): AnonymousResourceCollection
    {
        return UnavailabilityHistoryResource::collection(
            $unavailability->history()
                ->orderBy('created_at', 'desc')
                ->paginate()
        );
    }

    public function show(Unavailability $unavailability, UnavailabilityHistory $history): UnavailabilityHistoryResource
    {
        return new UnavailabilityHistoryResource($history);
    }
}
