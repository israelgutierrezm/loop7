<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\DataDeletionController;
use Illuminate\Support\Facades\Route;

// Endpoints públicos (sin auth): los llama Meta y el usuario final.
Route::post('/data-deletion/facebook', [DataDeletionController::class, 'facebook']);
Route::get('/data-deletion/status/{code}', [DataDeletionController::class, 'status']);
