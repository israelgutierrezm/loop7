<?php

declare(strict_types=1);

namespace App\Modules\Sso;

use App\Modules\Organizations\Events\OrganizationDeleted;
use App\Modules\Sso\Dns\DnsResolver;
use App\Modules\Sso\Dns\NativeDnsResolver;
use App\Modules\Sso\Listeners\ReleaseSsoOfDeletedOrganization;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Inicio de sesión único con SAML 2.0 (docs/03): dominios verificados por DNS,
 * conexión con el IdP de cada organización, SSO obligatorio y alta automática.
 */
class SsoServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
    }

    protected function bootModule(): void
    {
        Event::listen(OrganizationDeleted::class, ReleaseSsoOfDeletedOrganization::class);
    }
}
