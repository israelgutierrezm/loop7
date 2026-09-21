<?php

declare(strict_types=1);

use App\Modules\Automations\Http\Controllers\AutomationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/automations/meta', [AutomationController::class, 'meta']);
    Route::get('/automations', [AutomationController::class, 'index']);
    Route::post('/automations', [AutomationController::class, 'store']);
    Route::get('/automations/{automation}', [AutomationController::class, 'show']);
    Route::put('/automations/{automation}', [AutomationController::class, 'update']);
    Route::delete('/automations/{automation}', [AutomationController::class, 'destroy']);
});
