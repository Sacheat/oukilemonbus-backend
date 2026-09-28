<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyRouteSheetsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Génère les feuilles de route journalières pour tous les véhicules ayant des réservations confirmées.
 * Utilisée par le scheduler chaque soir pour préparer le lendemain, ou manuellement pour un rattrapage.
 */
class GenerateDailyRouteSheetsCommand extends Command
{
    protected $signature = 'route-sheets:generate-daily
        {serviceDate? : Date de service au format YYYY-MM-DD. Par defaut, demain.}
        {--force : Regenere meme si une feuille existe deja pour la date et le vehicule.}';

    protected $description = 'Génère les feuilles de route journalières par véhicule pour une date donnée.';

    public function handle(): int
    {
        $serviceDate = $this->serviceDate();

        if ($serviceDate === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->info(sprintf(
            'Génération des feuilles de route journalières pour le %s%s...',
            $serviceDate,
            $force ? ' (force)' : ''
        ));

        // Le job est exécuté directement (synchrone) plutôt que via la queue
        // pour permettre de lire le résumé et l'afficher dans le terminal.
        $summary = app()->call([
            new GenerateDailyRouteSheetsJob($serviceDate, $force),
            'handle',
        ]);

        foreach ($summary['generated'] as $generated) {
            $this->info(sprintf(
                'Véhicule %d : document %d généré (%s).',
                $generated['vehicle_id'],
                $generated['document_id'],
                $generated['file_path']
            ));
        }

        foreach ($summary['skipped'] as $skipped) {
            // Un véhicule est ignoré quand sa feuille existe déjà et que --force n'est pas passé.
            $this->line(sprintf(
                'Véhicule %d : déjà généré, ignoré.',
                $skipped['vehicle_id']
            ));
        }

        foreach ($summary['failed'] as $failed) {
            $this->error(sprintf(
                'Véhicule %d : échec (%s).',
                $failed['vehicle_id'],
                $failed['message']
            ));
        }

        $this->info(sprintf(
            'Terminé : %d générée(s), %d ignorée(s), %d échec(s).',
            count($summary['generated']),
            count($summary['skipped']),
            count($summary['failed'])
        ));

        // La commande échoue si au moins un véhicule a eu une erreur de génération.
        return $summary['failed'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Résout et valide la date de service.
     * Si aucun argument n'est passé, retourne demain dans le fuseau horaire de génération automatique.
     */
    private function serviceDate(): ?string
    {
        $argument = $this->argument('serviceDate');

        if ($argument === null) {
            // Le fuseau horaire de génération est configuré dans config/route_sheets.php
            // pour éviter les décalages lors des runs nocturnes.
            return CarbonImmutable::now(config('route_sheets.automatic_generation_timezone'))
                ->addDay()
                ->toDateString();
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $argument);
        } catch (Throwable) {
            $this->error('La date de service doit être valide et au format YYYY-MM-DD.');

            return null;
        }

        // Double vérification : createFromFormat peut retourner false ou accepter des dates partielles.
        if ($date === false || $date->format('Y-m-d') !== $argument) {
            $this->error('La date de service doit être valide et au format YYYY-MM-DD.');

            return null;
        }

        return $date->toDateString();
    }
}
