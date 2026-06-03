<?php

use App\Http\Controllers\Demo\BackendInspectorController;
use App\Http\Controllers\Demo\DemoActorController;
use App\Http\Controllers\Demo\DemoAdminController;
use App\Http\Controllers\Demo\DemoDashboardController;
use App\Http\Controllers\Demo\DemoDocumentController;
use App\Http\Controllers\Demo\DemoDriverController;
use App\Http\Controllers\Demo\DemoNotificationController;
use App\Http\Controllers\Demo\DemoOrganizationController;
use App\Http\Controllers\Demo\DemoPlanningController;
use App\Http\Controllers\Demo\DemoRequestController;
use App\Http\Controllers\Demo\DemoReservationController;
use Illuminate\Support\Facades\Route;

/* ROUTES DE LA DEMO - A NE PAS PRENDRE EN EXEMPLE POUR LA CREATION DE NOUVELLES FONCTIONNALITES */

// =========================================================================================================================================================================
// Dashboard de la demo
// =========================================================================================================================================================================
Route::get('/', function () {
    return view('welcome');
});

// =========================================================================================================================================================================
// Prefixage de toutes les routes de la demo avec le middleware "demo" qui gère l'acteur de démonstration et d'autres aspects spécifiques à la demo
// =========================================================================================================================================================================
Route::prefix('demo')->name('demo.')->group(function (): void {
    Route::get('/', DemoDashboardController::class)->name('dashboard');
    Route::post('/actor', [DemoActorController::class, 'update'])->name('actor');



    // Backend Inspector routes (permet de visualiser les requêtes et actions enregistrées par le Backend Inspector, ainsi que l'état de la session de démonstration)
    Route::prefix('inspector')
    ->name('inspector.')
    ->group(function (): void {
        Route::get('/live', [BackendInspectorController::class, 'live'])
        ->name('live');

        Route::get('/state', [BackendInspectorController::class, 'state'])
        ->name('state');
        });

        // Reservations routes
        Route::get('/reservations', [DemoReservationController::class, 'index'])->name('reservations');
        Route::get('/reservations/create', [DemoReservationController::class, 'create'])->name('reservations.create');
        Route::post('/reservations', [DemoReservationController::class, 'store'])->name('reservations.store');
        Route::get('/reservations/{reservation}/edit', [DemoReservationController::class, 'edit'])->name('reservations.edit');
        Route::patch('/reservations/{reservation}', [DemoReservationController::class, 'update'])->name('reservations.update');
        Route::post('/reservations/{reservation}/auto-assign', [DemoReservationController::class, 'autoAssign'])->name('reservations.auto-assign');
        Route::post('/reservations/{reservation}/assign', [DemoReservationController::class, 'assign'])->name('reservations.assign');
        Route::post('/reservations/{reservation}/council-decision', [DemoReservationController::class, 'councilDecision'])->name('reservations.council-decision');
        Route::post('/reservations/{reservation}/approve', [DemoReservationController::class, 'approve'])->name('reservations.approve');
        Route::post('/reservations/{reservation}/reject', [DemoReservationController::class, 'reject'])->name('reservations.reject');
        Route::post('/reservations/{reservation}/confirm', [DemoReservationController::class, 'confirm'])->name('reservations.confirm');
        Route::post('/reservations/{reservation}/cancel', [DemoReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::post('/reservations/{reservation}/conflicts/{conflict}/resolve', [DemoReservationController::class, 'resolveConflict'])->name('reservations.conflicts.resolve');

    // Driver route
    Route::get('/driver', DemoDriverController::class)->name('driver');

    // Planning route
    Route::get('/planning', DemoPlanningController::class)->name('planning');

    // Organizations route
    Route::get('/organizations', [DemoOrganizationController::class, 'index'])->name('organizations');


    // Documents routes
    Route::get('/documents', [DemoDocumentController::class, 'index'])->name('documents');
    Route::post('/documents/route-sheets', [DemoDocumentController::class, 'generateRouteSheet'])->name('documents.route-sheets');
    Route::get('/documents/{document}/download', [DemoDocumentController::class, 'download'])->name('documents.download');

    // Notifications routes
    Route::get('/notifications', [DemoNotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/preferences', [DemoNotificationController::class, 'updatePreferences'])->name('notifications.preferences');
    Route::post('/notifications/read-all', [DemoNotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notificationId}/read', [DemoNotificationController::class, 'markAsRead'])->name('notifications.read');

    // Requests routes (partie demande d'organisation et demande d'affiliation à une organisation)
    Route::get('/requests', [DemoRequestController::class, 'index'])->name('requests');
    Route::post('/requests/organizations', [DemoRequestController::class, 'storeOrg'])->name('requests.organizations.store');
    Route::post('/requests/organizations/{orgRegistrationRequest}/approve', [DemoRequestController::class, 'approveOrg'])->name('requests.organizations.approve');
    Route::post('/requests/organizations/{orgRegistrationRequest}/reject', [DemoRequestController::class, 'rejectOrg'])->name('requests.organizations.reject');
    Route::post('/requests/user-organizations', [DemoRequestController::class, 'storeUserOrg'])->name('requests.user-organizations.store');
    Route::post('/requests/user-organizations/{userOrgRequest}/approve', [DemoRequestController::class, 'approveUserOrg'])->name('requests.user-organizations.approve');
    Route::post('/requests/user-organizations/{userOrgRequest}/reject', [DemoRequestController::class, 'rejectUserOrg'])->name('requests.user-organizations.reject');

    // Admin route (exemple de route protégée par une autorisation spécifique à la demo)
    Route::get('/admin', [DemoAdminController::class, 'index'])->name('admin');
    Route::post('/admin/unavailabilities', [DemoAdminController::class, 'storeUnavailability'])->name('admin.unavailabilities.store');
    Route::post('/admin/unavailabilities/{unavailability}/cancel', [DemoAdminController::class, 'cancelUnavailability'])->name('admin.unavailabilities.cancel');
});
