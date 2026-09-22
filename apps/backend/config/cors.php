<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS restrictivo (docs/19)
|--------------------------------------------------------------------------
| Orígenes permitidos por env (coma-separados). Con supports_credentials=true
| NO se permite '*': deben listarse los orígenes de confianza (el SPA).
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,
];
