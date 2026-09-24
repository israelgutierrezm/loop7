<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationPreferencesController;
use App\Modules\Notifications\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

// Avisos del usuario en la Organization actual.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationsController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationsController::class, 'markRead']);
});

// Preferencias personales (no dependen de la Organization).
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/me/notification-preferences', [NotificationPreferencesController::class, 'show']);
    Route::put('/me/notification-preferences', [NotificationPreferencesController::class, 'update']);
});
