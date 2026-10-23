<?php

declare(strict_types=1);

use App\Modules\Reports\Http\Controllers\ClientReportController;
use App\Modules\Reports\Http\Controllers\ReportDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/brands/{brand}/reports', [ClientReportController::class, 'index']);
    Route::post('/brands/{brand}/reports', [ClientReportController::class, 'store']);
    Route::patch('/brands/{brand}/reports/{report}', [ClientReportController::class, 'update']);
    Route::delete('/brands/{brand}/reports/{report}', [ClientReportController::class, 'destroy']);
    Route::get('/brands/{brand}/reports/{report}/runs', [ClientReportController::class, 'runs']);
    // Generar un PDF cuesta: con límite propio.
    Route::post('/brands/{brand}/reports/{report}/runs', [ClientReportController::class, 'generate'])->middleware('throttle:10,10,reports-generate');
    // Envía correos a personas de fuera: límite estricto (y bloqueado al impersonar).
    Route::post('/brands/{brand}/reports/{report}/send', [ClientReportController::class, 'send'])->middleware('throttle:5,10,reports-send');
});

// PDF por URL firmada (sin sesión: la firma autoriza, 30 min).
Route::get('/reports/runs/{run}/download', ReportDownloadController::class)
    ->name('reports.download')
    ->middleware(['signed', 'throttle:60,1,reports-download'])
    ->where('run', '[0-9A-Za-z]{26}');
