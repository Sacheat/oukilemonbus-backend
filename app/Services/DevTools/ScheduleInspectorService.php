<?php

declare(strict_types=1);

namespace App\Services\DevTools;

use Illuminate\Support\Facades\Artisan;

final class ScheduleInspectorService
{
    /**
     * Retourne la sortie brute de schedule:list.
     * Le formatage est délégué à la commande.
     */
    public function list(): string
    {
        Artisan::call('schedule:list');

        return Artisan::output();
    }
}
