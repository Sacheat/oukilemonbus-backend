<?php

use App\Http\Controllers\Api\LocationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::middleware('permission:locations.view')->group(function (): void {
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/locations/{location}', [LocationController::class, 'show']);
    });

    Route::post('/locations', [LocationController::class, 'store'])
        ->middleware('permission:locations.create');

    Route::patch('/locations/{location}', [LocationController::class, 'update'])
        ->middleware('permission:locations.update');
});
