<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhookEndpointsController;
use App\Modules\Webhooks\Http\Middleware\EnsureWebhooksEnabled;
use Illuminate\Support\Facades\Route;

// Webhooks salientes (panel, sesión Sanctum): permiso api.manage + plan con API.
Route::middleware(['auth:sanctum', 'tenant', EnsureWebhooksEnabled::class])->group(function (): void {
    Route::get('/webhooks', [WebhookEndpointsController::class, 'index']);
    Route::post('/webhooks', [WebhookEndpointsController::class, 'store']);
    Route::patch('/webhooks/{endpoint}', [WebhookEndpointsController::class, 'update']);
    Route::delete('/webhooks/{endpoint}', [WebhookEndpointsController::class, 'destroy']);
    Route::post('/webhooks/{endpoint}/rotate-secret', [WebhookEndpointsController::class, 'rotateSecret']);
    Route::get('/webhooks/{endpoint}/deliveries', [WebhookEndpointsController::class, 'deliveries']);

    // Envían peticiones a servidores externos: con límite propio.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('/webhooks/{endpoint}/test', [WebhookEndpointsController::class, 'test']);
        Route::post('/webhooks/{endpoint}/deliveries/{delivery}/redeliver', [WebhookEndpointsController::class, 'redeliver']);
    });
});
