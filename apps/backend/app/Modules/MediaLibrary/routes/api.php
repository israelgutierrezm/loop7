<?php

declare(strict_types=1);

use App\Modules\MediaLibrary\Http\Controllers\MediaController;
use App\Modules\MediaLibrary\Http\Controllers\MediaFileController;
use App\Modules\MediaLibrary\Http\Controllers\MediaFoldersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/media', [MediaController::class, 'index']);
    Route::post('/brands/{brand}/media', [MediaController::class, 'store']);
    Route::get('/brands/{brand}/media/tags', [MediaController::class, 'tags']);
    Route::patch('/media/{asset}', [MediaController::class, 'update']);
    Route::delete('/media/{asset}', [MediaController::class, 'destroy']);

    Route::get('/brands/{brand}/media/folders', [MediaFoldersController::class, 'index']);
    Route::post('/brands/{brand}/media/folders', [MediaFoldersController::class, 'store']);
    Route::patch('/media/folders/{folder}', [MediaFoldersController::class, 'update']);
    Route::delete('/media/folders/{folder}', [MediaFoldersController::class, 'destroy']);
});

// Servir archivos privados por URL firmada (sin auth; valida la firma).
Route::get('/media/file/{asset}', [MediaFileController::class, 'show'])
    ->name('media.file')
    ->middleware('signed');
