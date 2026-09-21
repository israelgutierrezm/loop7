<?php

declare(strict_types=1);

use App\Modules\Campaigns\Http\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/campaigns', [CampaignController::class, 'index']);
    Route::post('/brands/{brand}/campaigns', [CampaignController::class, 'store']);
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
});
