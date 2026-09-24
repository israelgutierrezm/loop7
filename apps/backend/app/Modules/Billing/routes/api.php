<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Billing\Http\Controllers\PlatformBillingController;
use App\Modules\Billing\Http\Controllers\PlatformPlansController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Catálogo (no requiere contexto de tenant).
    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::get('/billing/gateways', [BillingController::class, 'gateways']);

    // Suscripción de la Organization actual.
    Route::middleware('tenant')->group(function (): void {
        Route::get('/billing/subscription', [BillingController::class, 'subscription']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices']);
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->middleware('throttle:10,1');
        Route::post('/billing/cancel', [BillingController::class, 'cancel']);
        Route::post('/billing/resume', [BillingController::class, 'resume']);
    });
});

// Gestión de planes, suscripciones y cobros (SUPERADMIN).
Route::prefix('platform')->middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
    Route::get('/entitlements', [PlatformPlansController::class, 'entitlements']);
    Route::get('/plans', [PlatformPlansController::class, 'index']);
    Route::post('/plans', [PlatformPlansController::class, 'store']);
    Route::put('/plans/{plan}', [PlatformPlansController::class, 'update']);
    Route::delete('/plans/{plan}', [PlatformPlansController::class, 'destroy']);

    Route::get('/add-ons', [PlatformPlansController::class, 'addOns']);
    Route::post('/add-ons', [PlatformPlansController::class, 'storeAddOn']);
    Route::put('/add-ons/{addOn}', [PlatformPlansController::class, 'updateAddOn']);

    Route::get('/subscriptions', [PlatformBillingController::class, 'subscriptions']);
    Route::get('/organizations/{organization}/billing', [PlatformBillingController::class, 'organization']);
    Route::post('/organizations/{organization}/billing/plan', [PlatformBillingController::class, 'changePlan']);
    Route::post('/organizations/{organization}/billing/extend-trial', [PlatformBillingController::class, 'extendTrial']);
    Route::post('/organizations/{organization}/billing/cancel', [PlatformBillingController::class, 'cancel']);
    Route::put('/organizations/{organization}/billing/overrides', [PlatformBillingController::class, 'saveOverrides']);
    Route::put('/organizations/{organization}/billing/add-ons', [PlatformBillingController::class, 'saveAddOns']);

    Route::get('/invoices', [PlatformBillingController::class, 'invoices']);
    Route::post('/invoices/{invoice}/mark-paid', [PlatformBillingController::class, 'markPaid']);
    Route::post('/invoices/{invoice}/void', [PlatformBillingController::class, 'voidInvoice']);
    Route::get('/transactions', [PlatformBillingController::class, 'transactions']);
    Route::get('/webhook-events', [PlatformBillingController::class, 'webhookEvents']);
    Route::post('/webhook-events/{event}/retry', [PlatformBillingController::class, 'retryWebhook']);
});
