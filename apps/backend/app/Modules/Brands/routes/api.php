<?php

declare(strict_types=1);

use App\Modules\Brands\Http\Controllers\BrandAccessController;
use App\Modules\Brands\Http\Controllers\BrandController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands', [BrandController::class, 'index']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    Route::patch('/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);

    Route::get('/brands/{brand}/access', [BrandAccessController::class, 'index']);
    Route::post('/brands/{brand}/access', [BrandAccessController::class, 'grant']);
    Route::delete('/brands/{brand}/access/{user}', [BrandAccessController::class, 'revoke']);
});
