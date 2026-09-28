<?php

namespace App\Console\Commands;

use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Génère manuellement une feuille de route pour une période arbitraire.
 * Complémentaire de generate-daily qui cible toujours une seule journée par véhicule.
 */
class GenerateRouteSheetCommand extends Command
{
    protected $signature = 'route-sheets:generate {startDate} {endDate}';

    protected $description = 'Génère une feuille de route pour une période donnée.';

    public function handle(RouteSheetDocumentService $routeSheetDocumentService): int
    {
        $startDate = $this->argument('startDate');
        $endDate = $this->argument('endDate');

        $this->info("Génération de la feuille de route du {$startDate} au {$endDate}...");

        try {
            $document = $routeSheetDocumentService->generateForPeriod($startDate, $endDate);
        } catch (InvalidArgumentException $exception) {
            // Les dates invalides ou les périodes vides remontent une InvalidArgumentException.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Feuille de route générée avec succès. Document ID : '.$document->id);
        $this->info('Fichier : '.$document->file_path);

        return self::SUCCESS;
    }
}
