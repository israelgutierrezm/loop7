<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| La interfaz es el SPA (apps/frontend). En producción Nginx sirve el SPA en "/"
| y sólo enruta /api, /sanctum, /up y /storage a Laravel; en desarrollo, abrir la
| URL del backend lleva al SPA.
*/

Route::get('/', fn () => redirect()->away(rtrim((string) config('app.frontend_url'), '/') . '/'));
