<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\ApiKeysController;
use Illuminate\Support\Facades\Route;

// Gestión de API keys desde el panel (sesión Sanctum).
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/api-keys', [ApiKeysController::class, 'index']);
    Route::post('/api-keys', [ApiKeysController::class, 'store']);
    Route::delete('/api-keys/{apiKey}', [ApiKeysController::class, 'destroy']);
});
