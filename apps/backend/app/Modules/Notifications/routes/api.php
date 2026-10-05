<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationPreferencesController;
use App\Modules\Notifications\Http\Controllers\NotificationsController;
use App\Modules\Notifications\Http\Controllers\PlatformNotificationChannelsController;
use App\Modules\Notifications\Http\Controllers\PushSubscriptionsController;
use App\Modules\Notifications\Http\Controllers\WhatsAppNumberController;
use Illuminate\Support\Facades\Route;

// Avisos del usuario en la Organization actual.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationsController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationsController::class, 'markRead']);
});

// Preferencias y canales personales (no dependen de la Organization).
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/me/notification-preferences', [NotificationPreferencesController::class, 'show']);
    Route::put('/me/notification-preferences', [NotificationPreferencesController::class, 'update']);

    Route::post('/me/push-subscriptions', [PushSubscriptionsController::class, 'store'])->middleware('throttle:20,1,push-subscriptions');
    Route::delete('/me/push-subscriptions', [PushSubscriptionsController::class, 'destroy'])->middleware('throttle:20,1,push-subscriptions');
    Route::post('/me/push-subscriptions/test', [PushSubscriptionsController::class, 'test'])->middleware('throttle:5,1,push-test');

    // Cada código es un mensaje con coste: límites estrictos (y diarios en el servicio).
    Route::post('/me/whatsapp', [WhatsAppNumberController::class, 'store'])->middleware('throttle:3,10,whatsapp-code');
    Route::post('/me/whatsapp/verify', [WhatsAppNumberController::class, 'verify'])->middleware('throttle:10,1,whatsapp-verify');
    Route::delete('/me/whatsapp', [WhatsAppNumberController::class, 'destroy']);
});

Route::prefix('platform')->middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
    Route::get('/notification-channels', [PlatformNotificationChannelsController::class, 'index']);
    Route::put('/notification-channels/webpush', [PlatformNotificationChannelsController::class, 'updateWebPush']);
    Route::post('/notification-channels/webpush/keys', [PlatformNotificationChannelsController::class, 'generatePushKeys'])->middleware('throttle:5,1,push-keys');
    Route::put('/notification-channels/whatsapp', [PlatformNotificationChannelsController::class, 'updateWhatsApp']);
    Route::post('/notification-channels/whatsapp/test', [PlatformNotificationChannelsController::class, 'testWhatsApp'])->middleware('throttle:10,1,whatsapp-test');
});
