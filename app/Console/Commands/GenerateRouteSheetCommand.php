<?php

namespace App\Console\Commands;

use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class GenerateRouteSheetCommand extends Command
{
    protected $signature = 'route-sheets:generate {startDate} {endDate}';

    protected $description = 'Genere une feuille de route pour une periode donnee.';

    public function handle(RouteSheetDocumentService $routeSheetDocumentService): int
    {
        $startDate = $this->argument('startDate');
        $endDate = $this->argument('endDate');

        $this->info("Generation de la feuille de route du {$startDate} au {$endDate}...");

        try {
            $document = $routeSheetDocumentService->generateForPeriod($startDate, $endDate);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Feuille de route generee avec succes. Document ID : '.$document->id);
        $this->info('Fichier : '.$document->file_path);

        return self::SUCCESS;
    }
}
