<?php

declare(strict_types=1);

use App\Modules\MediaLibrary\Http\Controllers\MediaController;
use App\Modules\MediaLibrary\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/media', [MediaController::class, 'index']);
    Route::post('/brands/{brand}/media', [MediaController::class, 'store']);
    Route::delete('/media/{asset}', [MediaController::class, 'destroy']);
});

// Servir archivos privados por URL firmada (sin auth; valida la firma).
Route::get('/media/file/{asset}', [MediaFileController::class, 'show'])
    ->name('media.file')
    ->middleware('signed');
