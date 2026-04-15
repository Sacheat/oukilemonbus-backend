<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrgRegistrationRequestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserOrgRequestController;
use App\Http\Controllers\Api\VehicleController;
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

        Route::middleware('permission:vehicles.view')->group(function (): void {
            Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
            Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
        });

        Route::post('/vehicles', [VehicleController::class, 'store'])
            ->middleware('permission:vehicles.create')
            ->name('vehicles.store');

        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])
            ->middleware('permission:vehicles.update')
            ->name('vehicles.update');

        Route::patch('/vehicles/{vehicle}/deactivate', [VehicleController::class, 'deactivate'])
            ->middleware('permission:vehicles.deactivate')
            ->name('vehicles.deactivate');

        Route::middleware('permission:drivers.view')->group(function (): void {
            Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
            Route::get('/drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        });

        Route::post('/drivers', [DriverController::class, 'store'])
            ->middleware('permission:drivers.create')
            ->name('drivers.store');

        Route::patch('/drivers/{driver}', [DriverController::class, 'update'])
            ->middleware('permission:drivers.update')
            ->name('drivers.update');

        Route::patch('/drivers/{driver}/deactivate', [DriverController::class, 'deactivate'])
            ->middleware('permission:drivers.deactivate')
            ->name('drivers.deactivate');

        Route::middleware('permission:org-registration-requests.view')->group(function (): void {
            Route::get('/org-registration-requests', [OrgRegistrationRequestController::class, 'index'])
                ->name('org-registration-requests.index');
            Route::get('/org-registration-requests/{orgRegistrationRequest}', [OrgRegistrationRequestController::class, 'show'])
                ->name('org-registration-requests.show');
        });

        Route::post('/org-registration-requests', [OrgRegistrationRequestController::class, 'store'])
            ->middleware('permission:org-registration-requests.create')
            ->name('org-registration-requests.store');

        Route::patch('/org-registration-requests/{orgRegistrationRequest}/approve', [OrgRegistrationRequestController::class, 'approve'])
            ->middleware('permission:org-registration-requests.review')
            ->name('org-registration-requests.approve');

        Route::patch('/org-registration-requests/{orgRegistrationRequest}/reject', [OrgRegistrationRequestController::class, 'reject'])
            ->middleware('permission:org-registration-requests.review')
            ->name('org-registration-requests.reject');

        Route::middleware('permission:user-org-requests.view')->group(function (): void {
            Route::get('/user-org-requests', [UserOrgRequestController::class, 'index'])
                ->name('user-org-requests.index');
            Route::get('/user-org-requests/{userOrgRequest}', [UserOrgRequestController::class, 'show'])
                ->name('user-org-requests.show');
        });

        Route::post('/user-org-requests', [UserOrgRequestController::class, 'store'])
            ->middleware('permission:user-org-requests.create')
            ->name('user-org-requests.store');

        Route::patch('/user-org-requests/{userOrgRequest}/approve', [UserOrgRequestController::class, 'approve'])
            ->middleware('permission:user-org-requests.review')
            ->name('user-org-requests.approve');

        Route::patch('/user-org-requests/{userOrgRequest}/reject', [UserOrgRequestController::class, 'reject'])
            ->middleware('permission:user-org-requests.review')
            ->name('user-org-requests.reject');

        Route::middleware('permission:organizations.view')->group(function (): void {
            Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        });

        Route::post('/organizations', [OrganizationController::class, 'store'])
            ->middleware('permission:organizations.create')
            ->name('organizations.store');

        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])
            ->middleware('permission:organizations.update')
            ->name('organizations.update');
    });
});
