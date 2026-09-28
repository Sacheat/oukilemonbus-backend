<?php

use App\Tooling\Inspector\Http\Controllers\InspectorController;
use App\Tooling\Toolbox\Http\Controllers\ToolboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Tooling — API d'administration
|--------------------------------------------------------------------------
|
| Chargé par App\Tooling\ToolingServiceProvider sous le préfixe
| /api/admin/tooling, nom api.admin.tooling.*, middleware :
| auth:sanctum, active, verified, permission:tooling.access.
|
*/

Route::prefix('inspector')->name('inspector.')->group(function (): void {
    Route::get('/status', [InspectorController::class, 'status'])->name('status');
    Route::get('/entries', [InspectorController::class, 'index'])->name('entries.index');
    Route::get('/entries/{inspectorEntry}', [InspectorController::class, 'show'])->name('entries.show');
    Route::delete('/entries', [InspectorController::class, 'purge'])->name('entries.purge');
    Route::get('/flows', [InspectorController::class, 'flows'])->name('flows.index');
    Route::get('/flows/{flowKey}', [InspectorController::class, 'flow'])->name('flows.show');
    Route::get('/users/{user}', [InspectorController::class, 'user'])->name('users.show');
});

Route::prefix('toolbox')->name('toolbox.')->group(function (): void {
    Route::get('/overview', [ToolboxController::class, 'overview'])->name('overview');
    Route::get('/permissions', [ToolboxController::class, 'permissions'])->name('permissions');
    Route::get('/users', [ToolboxController::class, 'users'])->name('users');
    Route::get('/api-catalog', [ToolboxController::class, 'apiCatalog'])->name('api-catalog');
    Route::get('/health', [ToolboxController::class, 'health'])
        ->middleware('throttle:10,1')
        ->name('health');
});
