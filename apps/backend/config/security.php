<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cabeceras de seguridad
    |--------------------------------------------------------------------------
    | Se aplican a todas las respuestas mediante el middleware SecurityHeaders
    | (docs/19). La CSP por defecto es estricta y apta para una API JSON; el SPA
    | define su propia CSP en su hosting.
    */
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), browsing-topics=()',
        'Content-Security-Policy' => env(
            'SECURITY_CSP',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | HSTS
    |--------------------------------------------------------------------------
    | Sólo se envía en peticiones sobre HTTPS para no fijar la política en
    | entornos locales por HTTP.
    */
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', true),
        'value' => env('SECURITY_HSTS_VALUE', 'max-age=31536000; includeSubDomains'),
    ],

    // Los proxies de confianza (TRUSTED_PROXIES) se configuran en bootstrap/app.php
    // porque deben aplicarse antes de que el contenedor de config esté disponible.
];
