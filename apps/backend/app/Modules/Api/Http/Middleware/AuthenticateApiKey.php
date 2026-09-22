<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Api\Services\ApiKeyService;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica peticiones de la API pública mediante API key (Bearer o X-Api-Key).
 * Resuelve la Organization de la key y fija el contexto de tenant (aislamiento).
 */
class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return ApiResponse::error('Falta la API key.', 'unauthenticated', status: 401);
        }

        $key = $this->keys->resolve($token);
        if ($key === null) {
            return ApiResponse::error('API key inválida o revocada.', 'unauthenticated', status: 401);
        }

        $organization = Organization::query()->find($key->organization_id);
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_API)) {
            return ApiResponse::error('El plan de la organización no incluye acceso a la API.', 'plan_limit_reached', status: 403);
        }

        // Aísla al tenant de la key (OrganizationScope).
        $this->tenant->setOrganization($organization);

        // Registra el uso sin escribir en cada request.
        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) > 60) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_key_id', $key->id);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Api-Key');

        return is_string($header) && $header !== '' ? $header : null;
    }
}
