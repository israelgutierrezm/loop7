<?php

declare(strict_types=1);

use App\Modules\Brands\Http\Controllers\BrandAccessController;
use App\Modules\Brands\Http\Controllers\BrandAudiencesController;
use App\Modules\Brands\Http\Controllers\BrandBrainController;
use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Brands\Http\Controllers\BrandKnowledgeController;
use App\Modules\Brands\Http\Controllers\BrandProductsController;
use App\Modules\Brands\Http\Controllers\BrandServicesController;
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

    // Brand Brain
    Route::get('/brands/{brand}/brain', [BrandBrainController::class, 'show']);
    Route::put('/brands/{brand}/brain/guidelines', [BrandBrainController::class, 'updateGuidelines']);

    Route::post('/brands/{brand}/audiences', [BrandAudiencesController::class, 'store']);
    Route::patch('/brands/{brand}/audiences/{child}', [BrandAudiencesController::class, 'update']);
    Route::delete('/brands/{brand}/audiences/{child}', [BrandAudiencesController::class, 'destroy']);

    Route::post('/brands/{brand}/products', [BrandProductsController::class, 'store']);
    Route::patch('/brands/{brand}/products/{child}', [BrandProductsController::class, 'update']);
    Route::delete('/brands/{brand}/products/{child}', [BrandProductsController::class, 'destroy']);

    Route::post('/brands/{brand}/services', [BrandServicesController::class, 'store']);
    Route::patch('/brands/{brand}/services/{child}', [BrandServicesController::class, 'update']);
    Route::delete('/brands/{brand}/services/{child}', [BrandServicesController::class, 'destroy']);

    Route::post('/brands/{brand}/knowledge', [BrandKnowledgeController::class, 'store']);
    Route::patch('/brands/{brand}/knowledge/{child}', [BrandKnowledgeController::class, 'update']);
    Route::delete('/brands/{brand}/knowledge/{child}', [BrandKnowledgeController::class, 'destroy']);
});
