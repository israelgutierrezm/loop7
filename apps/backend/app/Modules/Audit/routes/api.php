<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// La auditoría de la Organization es visible para perfiles con capacidad de
// administración (permiso organization.update: OWNER/ADMIN por defecto).
Route::middleware(['auth:sanctum', 'tenant', 'permission:organization.update'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
