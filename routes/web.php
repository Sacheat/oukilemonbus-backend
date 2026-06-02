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

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('demo')->name('demo.')->group(function (): void {
    Route::get('/', DemoDashboardController::class)->name('dashboard');
    Route::post('/actor', [DemoActorController::class, 'update'])->name('actor');

    Route::get('/driver', DemoDriverController::class)->name('driver');

    // Backend Inspector routes
    Route::prefix('inspector')
    ->name('inspector.')
    ->group(function (): void {
        Route::get('/live', [BackendInspectorController::class, 'live'])
            ->name('live');

        Route::get('/state', [BackendInspectorController::class, 'state'])
            ->name('state');
    });

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

    Route::get('/planning', DemoPlanningController::class)->name('planning');

    Route::get('/organizations', [DemoOrganizationController::class, 'index'])->name('organizations');

    Route::get('/documents', [DemoDocumentController::class, 'index'])->name('documents');
    Route::post('/documents/route-sheets', [DemoDocumentController::class, 'generateRouteSheet'])->name('documents.route-sheets');
    Route::get('/documents/{document}/download', [DemoDocumentController::class, 'download'])->name('documents.download');

    Route::get('/notifications', [DemoNotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/preferences', [DemoNotificationController::class, 'updatePreferences'])->name('notifications.preferences');
    Route::post('/notifications/read-all', [DemoNotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notificationId}/read', [DemoNotificationController::class, 'markAsRead'])->name('notifications.read');

    Route::get('/requests', [DemoRequestController::class, 'index'])->name('requests');
    Route::post('/requests/organizations', [DemoRequestController::class, 'storeOrg'])->name('requests.organizations.store');
    Route::post('/requests/organizations/{orgRegistrationRequest}/approve', [DemoRequestController::class, 'approveOrg'])->name('requests.organizations.approve');
    Route::post('/requests/organizations/{orgRegistrationRequest}/reject', [DemoRequestController::class, 'rejectOrg'])->name('requests.organizations.reject');
    Route::post('/requests/user-organizations', [DemoRequestController::class, 'storeUserOrg'])->name('requests.user-organizations.store');
    Route::post('/requests/user-organizations/{userOrgRequest}/approve', [DemoRequestController::class, 'approveUserOrg'])->name('requests.user-organizations.approve');
    Route::post('/requests/user-organizations/{userOrgRequest}/reject', [DemoRequestController::class, 'rejectUserOrg'])->name('requests.user-organizations.reject');

    Route::get('/admin', [DemoAdminController::class, 'index'])->name('admin');
    Route::post('/admin/unavailabilities', [DemoAdminController::class, 'storeUnavailability'])->name('admin.unavailabilities.store');
    Route::post('/admin/unavailabilities/{unavailability}/cancel', [DemoAdminController::class, 'cancelUnavailability'])->name('admin.unavailabilities.cancel');
});
