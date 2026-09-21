<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Catálogo (no requiere contexto de tenant).
    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::get('/billing/gateways', [BillingController::class, 'gateways']);

    // Suscripción de la Organization actual.
    Route::middleware('tenant')->group(function (): void {
        Route::get('/billing/subscription', [BillingController::class, 'subscription']);
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
        Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    });
});
