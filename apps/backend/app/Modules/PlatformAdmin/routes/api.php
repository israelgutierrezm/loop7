<?php

declare(strict_types=1);

use App\Modules\PlatformAdmin\Http\Controllers\DashboardController;
use App\Modules\PlatformAdmin\Http\Controllers\ImpersonationController;
use App\Modules\PlatformAdmin\Http\Controllers\PlatformAuditController;
use App\Modules\PlatformAdmin\Http\Controllers\PlatformJobsController;
use App\Modules\PlatformAdmin\Http\Controllers\PlatformOrganizationsController;
use App\Modules\PlatformAdmin\Http\Controllers\PlatformSettingsController;
use App\Modules\PlatformAdmin\Http\Controllers\PlatformUsersController;
use Illuminate\Support\Facades\Route;

// Configuración pública: datos legales, registro abierto y aviso del sistema.
Route::get('/public-config', [PlatformSettingsController::class, 'publicConfig'])->middleware('throttle:60,1');

Route::prefix('platform')->group(function (): void {
    // Panel SUPERADMIN.
    Route::middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/organizations', [PlatformOrganizationsController::class, 'index']);
        Route::get('/organizations/{organization}', [PlatformOrganizationsController::class, 'show']);
        Route::post('/organizations/{organization}/suspend', [PlatformOrganizationsController::class, 'suspend']);
        Route::post('/organizations/{organization}/activate', [PlatformOrganizationsController::class, 'activate']);

        Route::get('/users', [PlatformUsersController::class, 'index']);

        Route::get('/jobs', [PlatformJobsController::class, 'index']);
        Route::post('/jobs/{id}/retry', [PlatformJobsController::class, 'retry']);
        Route::delete('/jobs/{id}', [PlatformJobsController::class, 'forget']);

        Route::get('/audit-logs', [PlatformAuditController::class, 'index']);

        Route::get('/settings', [PlatformSettingsController::class, 'show']);
        Route::put('/settings', [PlatformSettingsController::class, 'update']);
        Route::get('/settings/trial-plans', [PlatformSettingsController::class, 'trialPlans']);

        Route::post('/impersonate/{user}', [ImpersonationController::class, 'start']);
    });

    // Finalizar impersonación: disponible para la sesión impersonada (no es admin).
    Route::middleware('auth:sanctum')->post('/impersonate/stop', [ImpersonationController::class, 'stop']);
});
