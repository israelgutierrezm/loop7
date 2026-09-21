<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::post('/brands/{brand}/analytics/sync', [AnalyticsController::class, 'sync']);
    Route::get('/brands/{brand}/analytics/export', [AnalyticsController::class, 'export']);
});
