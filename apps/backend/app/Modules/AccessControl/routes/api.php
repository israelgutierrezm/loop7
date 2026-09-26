<?php

declare(strict_types=1);

use App\Modules\AccessControl\Http\Controllers\RolesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/roles', [RolesController::class, 'index']);

    // Roles personalizados (docs/04): permisos roles.create / update / delete.
    Route::post('/roles', [RolesController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('/roles/{role}', [RolesController::class, 'update'])->middleware('throttle:30,1');
    Route::delete('/roles/{role}', [RolesController::class, 'destroy']);
});
