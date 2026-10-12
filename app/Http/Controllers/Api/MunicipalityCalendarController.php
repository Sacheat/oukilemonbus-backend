<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Municipalities\SaveCalendarPeriodRequest;
use App\Http\Resources\MunicipalityCalendarPeriodResource;
use App\Models\Municipality;
use App\Models\MunicipalityCalendarPeriod;
use App\Services\Calendar\ClosedDaysService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Calendrier d'une commune : fermetures (aucune réservation) et vacances scolaires (sautées par les séries).
 * GET renvoie aussi, dans `meta.public_holidays`, les jours fériés légaux calculés de l'année (lecture seule).
 * Peu de lignes par commune (une dizaine par an) : liste non paginée, filtrée par année.
 */
class MunicipalityCalendarController extends Controller
{
    public function __construct(
        private readonly ClosedDaysService $closedDays
    ) {}

    public function index(Request $request, Municipality $municipality): AnonymousResourceCollection
    {
        $year = (int) $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']])['year']
            ?: (int) now('Europe/Brussels')->year;

        $periods = MunicipalityCalendarPeriod::query()
            ->where('municipality_id', $municipality->id)
            // Périodes qui touchent l'année demandée (des vacances de Noël débordent sur janvier)
            ->whereDate('start_date', '<=', "{$year}-12-31")
            ->whereDate('end_date', '>=', "{$year}-01-01")
            ->orderBy('start_date')
            ->get();

        $holidays = collect($this->closedDays->publicHolidays($year))
            ->map(fn (string $label, string $date) => ['date' => $date, 'label' => $label])
            ->values();

        return MunicipalityCalendarPeriodResource::collection($periods)
            ->additional(['meta' => ['year' => $year, 'public_holidays' => $holidays]]);
    }

    public function store(SaveCalendarPeriodRequest $request, Municipality $municipality): JsonResponse
    {
        $period = $municipality->calendarPeriods()->create($request->validated());

        return (new MunicipalityCalendarPeriodResource($period))->response()->setStatusCode(201);
    }

    public function update(SaveCalendarPeriodRequest $request, MunicipalityCalendarPeriod $municipalityCalendarPeriod): MunicipalityCalendarPeriodResource
    {
        $municipalityCalendarPeriod->update($request->validated());

        return new MunicipalityCalendarPeriodResource($municipalityCalendarPeriod->refresh());
    }

    /** Suppression réelle : une période n'a pas d'historique à garder (les réservations déjà créées ne bougent pas) */
    public function destroy(MunicipalityCalendarPeriod $municipalityCalendarPeriod): Response
    {
        $municipalityCalendarPeriod->delete();

        return response()->noContent();
    }
}
