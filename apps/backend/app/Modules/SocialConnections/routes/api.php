<?php

declare(strict_types=1);

use App\Modules\SocialConnections\Http\Controllers\PlatformSocialProvidersController;
use App\Modules\SocialConnections\Http\Controllers\SocialCallbackController;
use App\Modules\SocialConnections\Http\Controllers\SocialConnectionsController;
use Illuminate\Support\Facades\Route;

// Callback OAuth público (valida el state internamente).
Route::get('/social/callback/{provider}', [SocialCallbackController::class, 'handle'])
    ->name('social.callback')
    ->middleware('throttle:30,1');

// Conexiones sociales del cliente.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/social/providers', [SocialConnectionsController::class, 'providers']);
    Route::get('/brands/{brand}/social/connections', [SocialConnectionsController::class, 'index']);
    Route::post('/brands/{brand}/social/connections/{provider}/authorize', [SocialConnectionsController::class, 'connect']);
    Route::post('/brands/{brand}/social/connections/{provider}/manual', [SocialConnectionsController::class, 'connectManual']);
    Route::delete('/social/connections/{connection}', [SocialConnectionsController::class, 'destroy']);
});

// Configuración de proveedores (SUPERADMIN).
Route::prefix('platform')->middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
    Route::get('/social-providers', [PlatformSocialProvidersController::class, 'index']);
    Route::put('/social-providers/{provider}', [PlatformSocialProvidersController::class, 'update']);
    Route::put('/social-providers/{provider}/credentials', [PlatformSocialProvidersController::class, 'setCredentials']);
});
