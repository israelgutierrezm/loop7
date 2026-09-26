<?php

declare(strict_types=1);

use App\Modules\Knowledge\Http\Controllers\KnowledgeDocumentsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/documents', [KnowledgeDocumentsController::class, 'index']);
    Route::post('/brands/{brand}/documents', [KnowledgeDocumentsController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/brands/{brand}/documents/search', [KnowledgeDocumentsController::class, 'search'])->middleware('throttle:30,1');
    Route::post('/brands/{brand}/documents/{document}/reindex', [KnowledgeDocumentsController::class, 'reindex'])->middleware('throttle:20,1');
    Route::get('/brands/{brand}/documents/{document}/download', [KnowledgeDocumentsController::class, 'download']);
    Route::delete('/brands/{brand}/documents/{document}', [KnowledgeDocumentsController::class, 'destroy']);
});
