<?php

declare(strict_types=1);

use App\Modules\Content\Http\Controllers\CalendarController;
use App\Modules\Content\Http\Controllers\ContentController;
use App\Modules\Content\Http\Controllers\ContentVariantsController;
use App\Modules\Content\Http\Controllers\ContentWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Acotado por Brand
    Route::get('/brands/{brand}/content', [ContentController::class, 'index']);
    Route::post('/brands/{brand}/content', [ContentController::class, 'store']);
    Route::get('/brands/{brand}/calendar', [CalendarController::class, 'index']);

    // Acotado por pieza de contenido
    Route::get('/content/{content}', [ContentController::class, 'show']);
    Route::patch('/content/{content}', [ContentController::class, 'update']);
    Route::delete('/content/{content}', [ContentController::class, 'destroy']);

    Route::post('/content/{content}/variants', [ContentVariantsController::class, 'store']);
    Route::patch('/variants/{variant}', [ContentVariantsController::class, 'update']);
    Route::put('/variants/{variant}/media', [ContentVariantsController::class, 'syncMedia']);
    Route::delete('/variants/{variant}', [ContentVariantsController::class, 'destroy']);

    // Flujo de aprobación / programación
    Route::post('/content/{content}/submit', [ContentWorkflowController::class, 'submit']);
    Route::post('/content/{content}/approve', [ContentWorkflowController::class, 'approve']);
    Route::post('/content/{content}/request-changes', [ContentWorkflowController::class, 'requestChanges']);
    Route::post('/content/{content}/comments', [ContentWorkflowController::class, 'comment']);
    Route::post('/content/{content}/schedule', [ContentWorkflowController::class, 'schedule']);
    Route::post('/content/{content}/publish-now', [ContentWorkflowController::class, 'publishNow']);
});
