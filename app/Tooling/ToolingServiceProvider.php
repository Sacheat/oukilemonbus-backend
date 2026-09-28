<?php

declare(strict_types=1);

namespace App\Tooling;

use App\Tooling\Inspector\Console\PruneInspectorEntriesCommand;
use App\Tooling\Inspector\Http\Middleware\RecordInspectorEntry;
use App\Tooling\Toolbox\Console\DevToolboxCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée unique du module Tooling (Backend Inspector + Dev Toolbox).
 *
 * Le cœur applicatif ne référence aucune classe du module : retirer ce provider de
 * bootstrap/providers.php suffit à désactiver entièrement les routes, le middleware,
 * les commandes et la planification. La table inspector_entries reste alors inutilisée.
 */
final class ToolingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->registerRoutes();
        $this->registerInspectorMiddleware();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DevToolboxCommand::class,
                PruneInspectorEntriesCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('tooling:inspector-prune')
                    ->dailyAt('03:30')
                    ->timezone('Europe/Brussels')
                    ->withoutOverlapping();
            });
        }
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::prefix('api/admin/tooling')
            ->name('api.admin.tooling.')
            ->middleware(['api', 'auth:sanctum', 'active', 'verified', 'permission:tooling.access'])
            ->group(base_path('routes/tooling.php'));
    }

    private function registerInspectorMiddleware(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->appendMiddlewareToGroup('api', RecordInspectorEntry::class);
        }
    }
}
