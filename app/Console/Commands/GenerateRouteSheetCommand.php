<?php

namespace App\Console\Commands;

use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Console\Command;

class GenerateRouteSheetCommand extends Command
{
    protected $signature = 'route-sheets:generate {startDate} {endDate}';

    protected $description = 'Génère une feuille de route pour une période donnée.';

    public function handle(RouteSheetDocumentService $routeSheetDocumentService): int
    {
        $startDate = $this->argument('startDate');
        $endDate = $this->argument('endDate');

        $this->info("Génération de la feuille de route du {$startDate} au {$endDate}...");

        $document = $routeSheetDocumentService->generateForPeriod($startDate, $endDate);

        $this->info('Feuille de route générée avec succès. Document ID : '.$document->id);
        $this->info('Fichier : '.$document->file_path);

        return self::SUCCESS;
    }
}
