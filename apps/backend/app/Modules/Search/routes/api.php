<?php

declare(strict_types=1);

use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant', 'throttle:120,1'])->get('/search', SearchController::class);
