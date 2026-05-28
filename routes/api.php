<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouncilDecisionController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MunicipalityController;
use App\Http\Controllers\Api\MunicipalityLocalityController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrgRegistrationRequestController;
use App\Http\Controllers\Api\PlanningController;
use App\Http\Controllers\Api\ReservationConflictController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RouteSheetDocumentController;
use App\Http\Controllers\Api\UnavailabilityController;
use App\Http\Controllers\Api\UnavailabilityHistoryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserOrgRequestController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function (): void {
    Route::get('/documents/{document}/signed-download', [DocumentController::class, 'signedDownload'])
        ->middleware('signed')
        ->middleware('throttle:30,1')
        ->name('documents.signed-download');

    // =========================================================================
    // Routes accessibles sans authentification
    // =========================================================================
    Route::middleware('guest')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1')
            ->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:20,1')
            ->name('auth.login');
    });

    // =========================================================================
    // Toutes les routes suivantes nécessitent une authentification
    // =========================================================================
    Route::middleware('auth:sanctum')->group(function (): void {
        /**
         * Authentication routes
         */
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->middleware('throttle:30,1')
            ->name('auth.logout');

        /**
         * In-app notifications and preferences routes
         */
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/summary', [NotificationController::class, 'summary'])->name('notifications.summary');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::get('/notifications/health', [NotificationController::class, 'health'])
            ->middleware('throttle:30,1')
            ->middleware('permission:notifications.monitor')
            ->name('notifications.health');
        Route::patch('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::patch('/notifications/read', [NotificationController::class, 'markManyAsRead'])->name('notifications.mark-many-read');
        Route::patch('/notifications/read-by-filter', [NotificationController::class, 'markByFilterAsRead'])->name('notifications.mark-by-filter-read');
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'show'])->name('notifications.preferences.show');
        Route::patch('/notifications/preferences', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');

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
         * Planning routes
         */
        Route::get('/planning/export', [PlanningController::class, 'export'])
            ->middleware('throttle:20,1')
            ->middleware('permission:reservations.view')
            ->name('planning.export');

        Route::post('/planning/exports', [PlanningController::class, 'requestExport'])
            ->middleware('throttle:20,1')
            ->middleware('permission:reservations.view')
            ->name('planning.exports.request');

        Route::get('/planning/exports/{planningExportRequest}', [PlanningController::class, 'showExport'])
            ->middleware('permission:reservations.view')
            ->name('planning.exports.show');

        Route::get('/planning', [PlanningController::class, 'index'])
            ->middleware('permission:reservations.view')
            ->name('planning.index');

        /**
         * Reservations routes
         */
        Route::middleware('permission:reservations.view')->group(function (): void {
            Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
            Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        });

        Route::post('/reservations/preview', [ReservationController::class, 'preview'])
            ->middleware('permission:reservations.create')
            ->name('reservations.preview');

        Route::post('/reservations', [ReservationController::class, 'store'])
            ->middleware('permission:reservations.create')
            ->name('reservations.store');

        Route::patch('/reservations/{reservation}', [ReservationController::class, 'update'])
            ->middleware('permission:reservations.update')
            ->name('reservations.update');

        Route::patch('/reservations/{reservation}/approve', [ReservationController::class, 'approve'])
            ->middleware('permission:reservations.approve')
            ->name('reservations.approve');

        Route::patch('/reservations/{reservation}/reject', [ReservationController::class, 'reject'])
            ->middleware('permission:reservations.reject')
            ->name('reservations.reject');

        Route::patch('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
            ->middleware('permission:reservations.cancel')
            ->name('reservations.cancel');

        Route::patch('/reservations/{reservation}/assign', [ReservationController::class, 'assign'])
            ->middleware('permission:reservations.assign')
            ->name('reservations.assign');

        Route::patch('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])
            ->middleware('permission:reservations.confirm')
            ->name('reservations.confirm');

        Route::get('/reservations/{reservation}/conflicts', [ReservationConflictController::class, 'index'])
            ->middleware('permission:reservations.manage-conflicts')
            ->name('reservations.conflicts.index');

        Route::patch('/reservations/{reservation}/conflicts/{conflict}/resolve', [ReservationConflictController::class, 'resolve'])
            ->middleware('permission:reservations.manage-conflicts')
            ->name('reservations.conflicts.resolve');

        /**
         * Council decisions routes
         */
        Route::middleware('permission:council-decisions.view')->group(function (): void {
            Route::get('/council-decisions', [CouncilDecisionController::class, 'index'])->name('council-decisions.index');
            Route::get('/council-decisions/{councilDecision}', [CouncilDecisionController::class, 'show'])->name('council-decisions.show');
        });

        Route::post('/council-decisions', [CouncilDecisionController::class, 'store'])
            ->middleware('permission:council-decisions.create')
            ->name('council-decisions.store');

        Route::patch('/council-decisions/{councilDecision}', [CouncilDecisionController::class, 'update'])
            ->middleware('permission:council-decisions.update')
            ->name('council-decisions.update');

        Route::post('/council-decisions/{councilDecision}/generate-document', [CouncilDecisionController::class, 'generateDocument'])
            ->middleware('permission:documents.generate')
            ->name('council-decisions.generate-document');

        Route::post('/documents/route-sheets/generate', [RouteSheetDocumentController::class, 'generate'])
            ->middleware('permission:documents.generate')
            ->name('documents.route-sheets.generate');

        /**
         * Documents routes
         */
        Route::middleware('permission:documents.view')->group(function (): void {
            Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        });

        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('permission:documents.download')
            ->name('documents.download');

        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->middleware('permission:documents.view')
            ->name('documents.show');

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
