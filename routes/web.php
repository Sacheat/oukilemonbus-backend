<?php

use App\Http\Controllers\Demo\BackendInspectorController;
use App\Http\Controllers\Demo\ToolboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('demo')->name('demo.')->group(function (): void {
    Route::get('/v2', fn () => view('demo.v2'))->name('v2');

    // Backend Inspector routes used by the v2 demo and the live inspector page.
    Route::prefix('inspector')->name('inspector.')->group(function (): void {
        Route::get('/live', [BackendInspectorController::class, 'live'])->name('live');
        Route::get('/state', [BackendInspectorController::class, 'state'])->name('state');
        Route::post('/reset', [BackendInspectorController::class, 'reset'])->name('reset');
        Route::get('/toolbox', [ToolboxController::class, 'data'])->name('toolbox');
    });
});
