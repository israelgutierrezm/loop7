<?php

declare(strict_types=1);

use App\Modules\Automations\Http\Controllers\AutomationController;
use App\Modules\Automations\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/automations/meta', [AutomationController::class, 'meta']);
    Route::get('/automations', [AutomationController::class, 'index']);
    Route::post('/automations', [AutomationController::class, 'store']);
    // Descarga un feed externo: con límite propio.
    Route::post('/automations/feed-preview', [AutomationController::class, 'feedPreview'])->middleware('throttle:10,1');
    Route::get('/automations/{automation}', [AutomationController::class, 'show']);
    Route::put('/automations/{automation}', [AutomationController::class, 'update']);
    Route::delete('/automations/{automation}', [AutomationController::class, 'destroy']);
    Route::post('/automations/{automation}/rotate-inbound-url', [AutomationController::class, 'rotateInboundUrl']);
});

// Webhook entrante (público): el token secreto de la URL identifica la regla.
Route::post('/hooks/automations/{token}', InboundWebhookController::class)
    ->middleware('throttle:automation-inbound');
