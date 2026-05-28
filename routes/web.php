<?php

use App\Http\Controllers\Demo\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('demo')->name('demo.')->group(function (): void {
    Route::get('/', [DemoController::class, 'dashboard'])->name('dashboard');
    Route::post('/actor', [DemoController::class, 'setActor'])->name('actor');

    Route::get('/reservations', [DemoController::class, 'reservations'])->name('reservations');
    Route::post('/reservations/{reservation}/auto-assign', [DemoController::class, 'autoAssign'])->name('reservations.auto-assign');
    Route::post('/reservations/{reservation}/assign', [DemoController::class, 'assign'])->name('reservations.assign');
    Route::post('/reservations/{reservation}/approve', [DemoController::class, 'approve'])->name('reservations.approve');
    Route::post('/reservations/{reservation}/confirm', [DemoController::class, 'confirm'])->name('reservations.confirm');
    Route::post('/reservations/{reservation}/cancel', [DemoController::class, 'cancel'])->name('reservations.cancel');

    Route::get('/planning', [DemoController::class, 'planning'])->name('planning');

    Route::get('/documents', [DemoController::class, 'documents'])->name('documents');
    Route::post('/documents/route-sheets', [DemoController::class, 'generateRouteSheet'])->name('documents.route-sheets');
    Route::get('/documents/{document}/download', [DemoController::class, 'downloadDocument'])->name('documents.download');

    Route::get('/notifications', [DemoController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/{notificationId}/read', [DemoController::class, 'markNotificationAsRead'])->name('notifications.read');

    Route::get('/requests', [DemoController::class, 'requests'])->name('requests');
    Route::post('/requests/organizations/{orgRegistrationRequest}/approve', [DemoController::class, 'approveOrgRequest'])->name('requests.organizations.approve');
    Route::post('/requests/organizations/{orgRegistrationRequest}/reject', [DemoController::class, 'rejectOrgRequest'])->name('requests.organizations.reject');
    Route::post('/requests/user-organizations/{requestId}/approve', [DemoController::class, 'approveUserOrgRequest'])->name('requests.user-organizations.approve');
    Route::post('/requests/user-organizations/{requestId}/reject', [DemoController::class, 'rejectUserOrgRequest'])->name('requests.user-organizations.reject');
});
