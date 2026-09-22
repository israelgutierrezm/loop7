<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PlatformGatewaysController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks entrantes de pasarelas (público; se valida por firma).
Route::post('/webhooks/payments/{gateway}', [WebhookController::class, 'handle'])
    ->middleware('throttle:120,1');

// Configuración de pasarelas (SUPERADMIN).
Route::prefix('platform')->middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
    Route::get('/payment-gateways', [PlatformGatewaysController::class, 'index']);
    Route::put('/payment-gateways/{gateway}', [PlatformGatewaysController::class, 'update']);
    Route::put('/payment-gateways/{gateway}/credentials', [PlatformGatewaysController::class, 'setCredentials']);
    Route::post('/payment-gateways/{gateway}/test', [PlatformGatewaysController::class, 'test']);
});
