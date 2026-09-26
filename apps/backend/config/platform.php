<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Seguridad de plataforma
    |--------------------------------------------------------------------------
    */

    // Exigir MFA activo a los usuarios SUPERADMIN antes de acceder al panel.
    'require_mfa_for_superadmin' => (bool) env('PLATFORM_REQUIRE_MFA_SUPERADMIN', false),

    // Vigencia de las invitaciones a Organizations (en horas).
    'invitation_ttl_hours' => (int) env('PLATFORM_INVITATION_TTL_HOURS', 72),

    // Organizaciones que un usuario puede crear y poseer (cada una estrena prueba).
    'max_owned_organizations' => (int) env('PLATFORM_MAX_OWNED_ORGANIZATIONS', 5),

    // Nombre del producto (para correos y UI).
    'product_name' => env('APP_NAME', 'Loop7 Social'),
];
