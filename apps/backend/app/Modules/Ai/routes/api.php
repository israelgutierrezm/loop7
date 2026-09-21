<?php

declare(strict_types=1);

use App\Modules\Ai\Http\Controllers\AiImageController;
use App\Modules\Ai\Http\Controllers\AiKeysController;
use App\Modules\Ai\Http\Controllers\AiTextController;
use App\Modules\Ai\Http\Controllers\AiUsageController;
use App\Modules\Ai\Http\Controllers\PlatformAiProvidersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Generación acotada por Brand
    Route::post('/brands/{brand}/ai/text', [AiTextController::class, 'generate']);
    Route::post('/brands/{brand}/ai/image', [AiImageController::class, 'generate']);

    // Uso y créditos de la Organization
    Route::get('/ai/usage', [AiUsageController::class, 'index']);

    // BYOK (claves propias)
    Route::get('/ai/keys', [AiKeysController::class, 'index']);
    Route::post('/ai/keys', [AiKeysController::class, 'store']);
    Route::delete('/ai/keys/{provider}', [AiKeysController::class, 'destroy']);
});

Route::prefix('platform')->middleware(['auth:sanctum', 'superadmin'])->group(function (): void {
    Route::get('/ai-providers', [PlatformAiProvidersController::class, 'index']);
    Route::put('/ai-providers/{provider}', [PlatformAiProvidersController::class, 'update']);
    Route::put('/ai-providers/{provider}/credentials', [PlatformAiProvidersController::class, 'setCredentials']);
});
