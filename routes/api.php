<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/**
 * API routes for managing locations
 * - GET /api/locations: List all locations
 * - POST /api/locations: Create a new location (requires formatted_address)
 * - GET /api/locations/{id}: Get details of a specific location
 */

Route::get('/locations',      [LocationController::class, 'index']);
Route::post('/locations',     [LocationController::class, 'store']);
Route::get('/locations/{id}', [LocationController::class, 'show']);
