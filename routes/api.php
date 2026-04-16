<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MunicipalityController;
use App\Http\Controllers\Api\MunicipalityLocalityController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrgRegistrationRequestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserOrgRequestController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\UnavailabilityController;
use App\Http\Controllers\Api\UnavailabilityHistoryController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function (): void {

    // =========================================================================
    // Routes accessibles sans authentification
    // =========================================================================
    Route::middleware('guest')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    // =========================================================================
    // Toutes les routes suivantes nécessitent une authentification
    // =========================================================================
    Route::middleware('auth:sanctum')->group(function (): void {
        /**
         * Authentication routes
         */
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        /**
         * Users routes
         */
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

        /**
         * Locations routes
         */
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

        /**
         * Municipalities and localities routes
         */
        Route::middleware('permission:municipalities.view')->group(function (): void {
            Route::get('/municipalities', [MunicipalityController::class, 'index'])->name('municipalities.index');
            Route::get('/municipalities/{municipality}', [MunicipalityController::class, 'show'])->name('municipalities.show');
        });

        Route::post('/municipalities', [MunicipalityController::class, 'store'])
            ->middleware('permission:municipalities.manage')
            ->name('municipalities.store');

        Route::patch('/municipalities/{municipality}', [MunicipalityController::class, 'update'])
            ->middleware('permission:municipalities.manage')
            ->name('municipalities.update');

        Route::post('/municipalities/{municipality}/sync-localities', [MunicipalityController::class, 'syncLocalities'])
            ->middleware('permission:municipalities.manage')
            ->name('municipalities.sync-localities');

        Route::post('/municipalities/{municipality}/localities', [MunicipalityLocalityController::class, 'store'])
            ->middleware('permission:municipalities.manage')
            ->name('municipality-localities.store');

        Route::patch('/municipality-localities/{municipalityLocality}', [MunicipalityLocalityController::class, 'update'])
            ->middleware('permission:municipalities.manage')
            ->name('municipality-localities.update');

        Route::patch('/municipality-localities/{municipalityLocality}/deactivate', [MunicipalityLocalityController::class, 'deactivate'])
            ->middleware('permission:municipalities.manage')
            ->name('municipality-localities.deactivate');

        /**
         * Vehicles routes
         */
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

        /**
         * Drivers routes
         */
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

        /**
         * Organization and registration requests routes
         */
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

        /**
         * User organization requests routes
         */
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

        /**
         * Organizations routes
         */
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

        /**
         * Unavailabilities routes
         */
        Route::middleware('permission:unavailabilities.view')->group(function (): void {
            Route::get('/unavailabilities', [UnavailabilityController::class, 'index'])->name('unavailabilities.index');
            Route::get('/unavailabilities/{unavailability}', [UnavailabilityController::class, 'show'])->name('unavailabilities.show');
        });

        Route::post('/unavailabilities', [UnavailabilityController::class, 'store'])
            ->middleware('permission:unavailabilities.create')
            ->name('unavailabilities.store');

        Route::patch('/unavailabilities/{unavailability}', [UnavailabilityController::class, 'update'])
            ->middleware('permission:unavailabilities.update')
            ->name('unavailabilities.update');

        Route::patch('/unavailabilities/{unavailability}/cancel', [UnavailabilityController::class, 'cancel'])
            ->middleware('permission:unavailabilities.cancel')
            ->name('unavailabilities.cancel');

        /**
         * Unavailabilities history routes (lecture seule)
         * Pas besoin d'une permission séparée pour l'historique car elle est liée à la permission de voir les indisponibilités.
         */
        Route::middleware('permission:unavailabilities.view')->group(function (): void {
            Route::get('/unavailabilities/{unavailability}/history', [UnavailabilityHistoryController::class, 'index'])
                ->name('unavailabilities.history.index');
            Route::get('/unavailabilities/{unavailability}/history/{history}', [UnavailabilityHistoryController::class, 'show'])
                ->name('unavailabilities.history.show');
        });
    });
});
