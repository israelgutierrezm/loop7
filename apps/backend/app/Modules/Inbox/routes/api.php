<?php

declare(strict_types=1);

use App\Modules\Inbox\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Acotado por Brand
    Route::get('/brands/{brand}/inbox', [InboxController::class, 'index']);
    Route::post('/brands/{brand}/inbox/sync', [InboxController::class, 'sync']);

    // Acotado por conversación
    Route::get('/inbox/{conversation}', [InboxController::class, 'show']);
    Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply']);
    Route::post('/inbox/{conversation}/note', [InboxController::class, 'note']);
    Route::post('/inbox/{conversation}/assign', [InboxController::class, 'assign']);
    Route::post('/inbox/{conversation}/status', [InboxController::class, 'updateStatus']);
    Route::put('/inbox/{conversation}/tags', [InboxController::class, 'tags']);
    Route::post('/inbox/{conversation}/suggest', [InboxController::class, 'suggest']);
});
