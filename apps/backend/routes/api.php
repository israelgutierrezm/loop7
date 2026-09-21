<?php

declare(strict_types=1);

use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
| Rutas transversales de la API v1. Las rutas de dominio se registran desde
| los ServiceProviders de cada módulo (app/Modules/<Modulo>/routes/api.php).
*/

Route::get('/health', fn () => ApiResponse::success([
    'status' => 'ok',
    'app' => config('app.name'),
    'version' => 'v1',
    'time' => now()->toIso8601String(),
], 'Servicio operativo.'));
