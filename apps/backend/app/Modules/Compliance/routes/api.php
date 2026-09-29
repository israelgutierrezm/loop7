<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\DataDeletionController;
use App\Modules\Compliance\Services\DataDeletionService;
use Illuminate\Support\Facades\Route;

// Endpoints públicos (sin auth): los llama Meta y el usuario final. Cada app de
// Meta (Facebook, que también cubre Instagram, y Threads) firma con su secreto.
Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('/data-deletion/{provider}', [DataDeletionController::class, 'dataDeletion'])
        ->whereIn('provider', DataDeletionService::apps());
    Route::post('/deauthorize/{provider}', [DataDeletionController::class, 'deauthorize'])
        ->whereIn('provider', DataDeletionService::apps());
});
Route::get('/data-deletion/status/{code}', [DataDeletionController::class, 'status']);
