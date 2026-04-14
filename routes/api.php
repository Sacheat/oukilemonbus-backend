<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::middleware('permission:users.view')->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        });

        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:users.create')
            ->name('users.store');

        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/password', [UserController::class, 'updatePassword'])->name('users.update-password');

        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])
            ->middleware('permission:users.update-status')
            ->name('users.update-status');

        Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->middleware('permission:users.deactivate')
            ->name('users.deactivate');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::middleware('permission:locations.view')->group(function (): void {
            Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
            Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
        });

        Route::post('/locations', [LocationController::class, 'store'])
            ->middleware('permission:locations.create')
            ->name('locations.store');

        Route::patch('/locations/{location}', [LocationController::class, 'update'])
            ->middleware('permission:locations.update')
            ->name('locations.update');
    });
});
