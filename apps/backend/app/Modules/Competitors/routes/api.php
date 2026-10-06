<?php

declare(strict_types=1);

use App\Modules\Competitors\Http\Controllers\CompetitorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/competitors', [CompetitorController::class, 'index']);
    Route::get('/brands/{brand}/competitors/benchmark', [CompetitorController::class, 'benchmark']);
    // Alta y nuevas cuentas consultan la API de la red: con límite propio.
    Route::post('/brands/{brand}/competitors', [CompetitorController::class, 'store'])->middleware('throttle:20,1,competitors-lookup');
    Route::patch('/brands/{brand}/competitors/{competitor}', [CompetitorController::class, 'update']);
    Route::delete('/brands/{brand}/competitors/{competitor}', [CompetitorController::class, 'destroy']);
    Route::post('/brands/{brand}/competitors/{competitor}/accounts', [CompetitorController::class, 'addAccount'])->middleware('throttle:20,1,competitors-lookup');
    Route::delete('/brands/{brand}/competitors/{competitor}/accounts/{account}', [CompetitorController::class, 'removeAccount']);
    Route::post('/brands/{brand}/competitors/{competitor}/sync', [CompetitorController::class, 'sync'])->middleware('throttle:10,1,competitors-sync');
});
