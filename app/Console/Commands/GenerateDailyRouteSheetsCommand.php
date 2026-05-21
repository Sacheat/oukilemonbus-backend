<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyRouteSheetsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class GenerateDailyRouteSheetsCommand extends Command
{
    protected $signature = 'route-sheets:generate-daily
        {serviceDate? : Date de service au format YYYY-MM-DD. Par defaut, demain.}
        {--force : Regenere meme si une feuille existe deja pour la date et le vehicule.}';

    protected $description = 'Genere les feuilles de route journalieres par vehicule pour une date donnee.';

    public function handle(): int
    {
        $serviceDate = $this->serviceDate();

        if ($serviceDate === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->info(sprintf(
            'Generation des feuilles de route journalieres pour le %s%s...',
            $serviceDate,
            $force ? ' (force)' : ''
        ));

        $summary = app()->call([
            new GenerateDailyRouteSheetsJob($serviceDate, $force),
            'handle',
        ]);

        foreach ($summary['generated'] as $generated) {
            $this->info(sprintf(
                'Vehicule %d : document %d genere (%s).',
                $generated['vehicle_id'],
                $generated['document_id'],
                $generated['file_path']
            ));
        }

        foreach ($summary['skipped'] as $skipped) {
            $this->line(sprintf(
                'Vehicule %d : deja genere, ignore.',
                $skipped['vehicle_id']
            ));
        }

        foreach ($summary['failed'] as $failed) {
            $this->error(sprintf(
                'Vehicule %d : echec (%s).',
                $failed['vehicle_id'],
                $failed['message']
            ));
        }

        $this->info(sprintf(
            'Termine : %d generee(s), %d ignoree(s), %d echec(s).',
            count($summary['generated']),
            count($summary['skipped']),
            count($summary['failed'])
        ));

        return $summary['failed'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function serviceDate(): ?string
    {
        $argument = $this->argument('serviceDate');

        if ($argument === null) {
            return CarbonImmutable::now(config('route_sheets.automatic_generation_timezone'))
                ->addDay()
                ->toDateString();
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $argument);
        } catch (Throwable) {
            $this->error('La date de service doit etre valide et au format YYYY-MM-DD.');

            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $argument) {
            $this->error('La date de service doit etre valide et au format YYYY-MM-DD.');

            return null;
        }

        return $date->toDateString();
    }
}
