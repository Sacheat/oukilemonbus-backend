<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Driver;
use App\Models\User;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\Generators\RouteSheetGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Feuilles de route vues par un chauffeur (cahier des charges §5.9 et §5.10) :
 *   - feuille de la semaine du chauffeur : PDF généré à la demande (toujours à jour, non enregistré), ses seules
 *     missions confirmées, avec des cases à remplir à la main (support papier) ;
 *   - liste paginée des feuilles officielles (journalières, par véhicule), dernière version seulement.
 * Qui : le chauffeur pour lui-même ; un agent ou un admin pour un chauffeur donné (driver_id).
 */
class DriverRouteSheetService
{
    private const INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function __construct(
        private readonly RouteSheetContextBuilder $contextBuilder,
        private readonly RouteSheetGenerator $generator,
        private readonly DocumentAccessService $documentAccessService
    ) {}

    /**
     * Chauffeur concerné :
     *   chauffeur connecté → lui-même (un autre driver_id : 403) ;
     *   agent / admin      → le driver_id demandé (obligatoire) ;
     *   sinon              → 403.
     */
    public function resolveDriver(User $user, ?int $driverId): Driver
    {
        $ownDriver = $user->hasRole('chauffeur') ? $user->driver : null;

        if ($ownDriver !== null && ($driverId === null || $driverId === $ownDriver->getKey())) {
            return $ownDriver;
        }

        if (! $user->hasAnyRole(self::INTERNAL_ROLES)) {
            throw new AuthorizationException('Vous ne pouvez consulter que votre propre feuille de route.');
        }

        if ($driverId === null) {
            throw ValidationException::withMessages(['driver_id' => 'Indiquez le chauffeur.']);
        }

        return Driver::query()->findOrFail($driverId);
    }

    /** @return array{file_name: string, content: string} */
    public function renderWeek(Driver $driver, string $dayInWeek): array
    {
        $context = $this->contextBuilder->buildForDriverWeek($driver, $dayInWeek);

        return [
            'file_name' => $this->generator->defaultPdfFileName($context),
            'content' => $this->generator->generatePdf($context),
        ];
    }

    /**
     * Feuilles officielles visibles (chauffeur : celles qui contiennent au moins une de ses missions), dernière
     * version de chaque jour et véhicule, la plus récente d'abord, 15 par page.
     */
    public function officialSheets(User $user, ?string $dateFrom, ?string $dateTo): LengthAwarePaginator
    {
        $ownDriverId = $user->hasRole('chauffeur') ? $user->driver?->getKey() : null;

        return $this->documentAccessService->visibleQueryFor($user)
            ->where('documents.document_type', DocumentType::FEUILLE_ROUTE->value)
            ->whereNotNull('documents.service_date')
            // Dernière version : aucune version plus récente (disponible) pour le même jour et le même véhicule
            ->whereNotExists(function ($newer): void {
                $newer->selectRaw('1')
                    ->from('documents as newer')
                    ->where('newer.document_type', DocumentType::FEUILLE_ROUTE->value)
                    ->where('newer.is_available', true)
                    ->whereColumn('newer.service_date', 'documents.service_date')
                    ->whereColumn('newer.vehicle_id', 'documents.vehicle_id')
                    ->whereColumn('newer.version', '>', 'documents.version');
            })
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('documents.service_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('documents.service_date', '<=', $dateTo))
            ->with('vehicle')
            ->withCount('routeSheetReservations as trip_count')
            ->when($ownDriverId !== null, fn (Builder $query) => $query->withCount([
                'routeSheetReservations as own_trip_count' => fn (Builder $reservations) => $reservations->where('driver_id', $ownDriverId),
            ]))
            ->orderByDesc('documents.service_date')
            ->orderBy('documents.vehicle_id')
            ->paginate(15);
    }
}
