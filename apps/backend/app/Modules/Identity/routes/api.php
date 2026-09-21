<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

// ---- Autenticación pública (con throttling anti fuerza bruta) --------------
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/auth/register', [RegisterController::class, 'store']);
    Route::post('/auth/login', [AuthenticatedSessionController::class, 'store']);
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);
});

// Verificación de correo (enlace firmado desde el email; redirige al SPA).
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// ---- Sesión autenticada ----------------------------------------------------
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy']);

    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::put('/me/password', [ProfileController::class, 'updatePassword']);

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');

    Route::post('/me/two-factor/enable', [TwoFactorController::class, 'enable']);
    Route::post('/me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
    Route::delete('/me/two-factor', [TwoFactorController::class, 'disable']);
});
