<?php

declare(strict_types=1);

use App\Modules\AccessControl\Http\Controllers\RolesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/roles', [RolesController::class, 'index']);
});
