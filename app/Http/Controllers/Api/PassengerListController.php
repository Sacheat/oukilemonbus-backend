<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Reservation;
use App\Services\Documents\PassengerListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Liste des passagers d'une réservation (carnet de bord) :
 *   GET    /reservations/{id}/passenger-list → { data: document | null, meta: { can_replace, closes_at, reason } }
 *   POST   /reservations/{id}/passenger-list (multipart, champ passenger_list) → 201, remplace la précédente
 *   DELETE /reservations/{id}/passenger-list → 204
 * Le fichier se télécharge par GET /documents/{id}/download, avec les règles d'accès des documents.
 */
class PassengerListController extends Controller
{
    public function __construct(
        private readonly PassengerListService $passengerLists
    ) {}

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);

        $document = $this->passengerLists->current($reservation);
        $window = $request->user()->can('managePassengerList', $reservation)
            ? $this->passengerLists->window($reservation, $request->user())
            : ['can_replace' => false, 'closes_at' => null, 'reason' => null];

        return response()->json([
            'data' => $document ? new DocumentResource($document->load('user')) : null,
            'meta' => $window,
        ]);
    }

    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('managePassengerList', $reservation);

        $validated = $request->validate(
            [PassengerListService::FIELD => ['required', 'file']],
            [PassengerListService::FIELD.'.required' => 'Choisissez le fichier de la liste des passagers.']
        );

        $document = $this->passengerLists->replace($reservation, $validated[PassengerListService::FIELD], $request->user());

        return (new DocumentResource($document->load('user')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('managePassengerList', $reservation);

        $this->passengerLists->remove($reservation, $request->user());

        return response()->noContent();
    }
}
