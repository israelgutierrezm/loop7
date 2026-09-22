<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\McpController;
use App\Modules\Api\Http\Controllers\PublicAnalyticsController;
use App\Modules\Api\Http\Controllers\PublicBrandsController;
use App\Modules\Api\Http\Controllers\PublicContentController;
use App\Modules\Api\Http\Controllers\PublicMeController;
use Illuminate\Support\Facades\Route;

/**
 * API pública v1 (prefijo api/public/v1). Autenticada por API key + rate limit
 * por key; cada endpoint exige su scope.
 */
Route::middleware(['apikey', 'throttle:public-api'])->group(function (): void {
    Route::get('/me', [PublicMeController::class, 'show']);

    Route::get('/brands', [PublicBrandsController::class, 'index'])->middleware('scope:brands:read');
    Route::get('/brands/{brand}/content', [PublicContentController::class, 'index'])->middleware('scope:content:read');
    Route::post('/brands/{brand}/content', [PublicContentController::class, 'store'])->middleware('scope:content:write');
    Route::get('/brands/{brand}/analytics', [PublicAnalyticsController::class, 'overview'])->middleware('scope:analytics:read');

    // Servidor MCP (JSON-RPC): las herramientas se filtran por los scopes de la key.
    Route::post('/mcp', [McpController::class, 'handle']);
});
