<?php

declare(strict_types=1);

use App\Modules\Organizations\Http\Controllers\BrandingController;
use App\Modules\Organizations\Http\Controllers\ContextController;
use App\Modules\Organizations\Http\Controllers\DashboardController;
use App\Modules\Organizations\Http\Controllers\InvitationsController;
use App\Modules\Organizations\Http\Controllers\MembersController;
use App\Modules\Organizations\Http\Controllers\OrganizationController;
use App\Modules\Organizations\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Sin contexto de tenant (operan sobre "mis" organizaciones).
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:10,60,organization-create');
    Route::post('/invitations/accept', [InvitationsController::class, 'accept']);

    // Contexto de la Organization actual (resuelto por el middleware tenant).
    Route::middleware('tenant')->group(function (): void {
        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::patch('/organization', [OrganizationController::class, 'update']);
        // Piden la contraseña: límite compartido con /me/password y demás contra adivinarla.
        Route::delete('/organization', [OrganizationController::class, 'destroy'])->middleware('throttle:6,1,password-confirm');
        Route::post('/organization/transfer-ownership', [OrganizationController::class, 'transferOwnership'])->middleware('throttle:6,1,password-confirm');
        Route::get('/context', [ContextController::class, 'show']);
        Route::get('/dashboard', DashboardController::class);

        Route::get('/organization/members', [MembersController::class, 'index']);
        Route::patch('/organization/members/{user}', [MembersController::class, 'update']);
        Route::delete('/organization/members/{user}', [MembersController::class, 'destroy']);

        Route::get('/organization/invitations', [InvitationsController::class, 'index']);
        Route::post('/organization/invitations', [InvitationsController::class, 'store']);
        Route::delete('/organization/invitations/{invitation}', [InvitationsController::class, 'destroy']);

        // Controles Enterprise: exigir la verificación en dos pasos.
        Route::get('/organization/security', [SecurityController::class, 'show']);
        Route::put('/organization/security', [SecurityController::class, 'update']);

        // Marca blanca
        Route::get('/organization/branding', [BrandingController::class, 'show']);
        Route::put('/organization/branding', [BrandingController::class, 'update']);
        Route::post('/organization/branding/logo', [BrandingController::class, 'uploadLogo'])->middleware('throttle:10,1,branding-logo');
        Route::delete('/organization/branding/logo', [BrandingController::class, 'deleteLogo']);
    });
});

// Logo de marca blanca por URL firmada (sin sesión; valida la firma).
Route::get('/branding/{organization}/logo', [BrandingController::class, 'logo'])
    ->name('branding.logo')
    ->middleware('signed');
