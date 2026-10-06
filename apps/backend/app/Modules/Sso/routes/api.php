<?php

declare(strict_types=1);

use App\Modules\Sso\Http\Controllers\SsoLoginController;
use App\Modules\Sso\Http\Controllers\SsoSettingsController;
use Illuminate\Support\Facades\Route;

// ---- Inicio de sesión único (público) --------------------------------------
Route::post('/sso/discover', [SsoLoginController::class, 'discover'])->middleware('throttle:10,1,sso-discover');
Route::post('/sso/exchange', [SsoLoginController::class, 'exchange'])->middleware('throttle:10,1,sso-exchange');
Route::get('/sso/{organization}/metadata', [SsoLoginController::class, 'metadata'])
    ->middleware('throttle:60,1,sso-metadata')
    ->where('organization', '[0-9A-Za-z]{26}');
// El IdP envía la respuesta con un POST desde el navegador (sin CSRF: bootstrap/app.php).
Route::post('/sso/{organization}/acs', [SsoLoginController::class, 'acs'])
    ->middleware('throttle:30,1,sso-acs')
    ->where('organization', '[0-9A-Za-z]{26}');

// ---- Configuración de la organización actual --------------------------------
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/organization/sso', [SsoSettingsController::class, 'show']);
    Route::put('/organization/sso', [SsoSettingsController::class, 'update']);
    Route::post('/organization/sso/metadata', [SsoSettingsController::class, 'parseMetadata'])->middleware('throttle:20,1,sso-metadata-parse');
    Route::post('/organization/sso/domains', [SsoSettingsController::class, 'addDomain'])->middleware('throttle:20,1,sso-domains');
    // Consulta el DNS: con límite propio.
    Route::post('/organization/sso/domains/{domain}/verify', [SsoSettingsController::class, 'verifyDomain'])->middleware('throttle:10,1,sso-domain-verify');
    Route::delete('/organization/sso/domains/{domain}', [SsoSettingsController::class, 'removeDomain']);
    Route::post('/organization/sso/test', [SsoSettingsController::class, 'test'])->middleware('throttle:10,1,sso-test');
    Route::get('/organization/sso/test/{token}', [SsoSettingsController::class, 'testResult'])->where('token', '[A-Za-z0-9]{40}');
});
