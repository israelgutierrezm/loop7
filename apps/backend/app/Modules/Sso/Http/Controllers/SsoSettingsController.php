<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Http\Requests\IdpMetadataRequest;
use App\Modules\Sso\Http\Requests\SsoDomainRequest;
use App\Modules\Sso\Http\Requests\UpdateSsoConnectionRequest;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Modules\Sso\Services\SsoConfiguration;
use App\Modules\Sso\Services\SsoDomains;
use App\Modules\Sso\Services\SsoService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * SSO de la organización actual (docs/03): conexión SAML, dominios verificados
 * y prueba de conexión. Exige `organization.update` y el plan con feature.sso
 * (consultar no exige el plan: así se ve qué incluye).
 */
class SsoSettingsController extends Controller
{
    public function __construct(
        private readonly SsoConfiguration $configuration,
        private readonly SsoDomains $domains,
        private readonly SsoService $sso,
        private readonly TenantContext $tenant,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);

        return ApiResponse::success($this->configuration->present($this->organization()));
    }

    public function update(UpdateSsoConnectionRequest $request): JsonResponse
    {
        $organization = $this->entitled();
        $this->configuration->update($organization, $request->connection());

        return ApiResponse::success($this->configuration->present($organization), 'Configuración de SSO guardada.');
    }

    /**
     * Lee los metadatos XML del IdP y devuelve los campos (no guarda nada).
     */
    public function parseMetadata(IdpMetadataRequest $request): JsonResponse
    {
        $this->entitled();

        return ApiResponse::success($this->configuration->parseMetadata((string) $request->validated('xml')));
    }

    public function addDomain(SsoDomainRequest $request): JsonResponse
    {
        $organization = $this->entitled();
        $domain = $this->domains->add($organization, (string) $request->validated('domain'));

        return ApiResponse::success($this->configuration->presentDomain($domain), 'Dominio añadido. Publica el registro TXT para verificarlo.', status: 201);
    }

    public function verifyDomain(Request $request, string $domain): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $this->entitled();
        $model = $this->resolveDomain($domain);

        $verified = $this->domains->verify($model);

        return ApiResponse::success(
            $this->configuration->presentDomain($model->refresh()),
            $verified ? 'Dominio verificado.' : 'Aún no encontramos el registro TXT. Los cambios de DNS pueden tardar unos minutos.',
        );
    }

    public function removeDomain(Request $request, string $domain): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $organization = $this->organization();
        $model = $this->resolveDomain($domain);

        // Sin dominios verificados el SSO activo dejaría de funcionar sin avisar.
        $connection = $this->sso->connectionOf($organization);
        if ($connection !== null && $connection->is_enabled && $model->isVerified()) {
            $others = OrganizationDomain::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull('verified_domain')
                ->whereKeyNot($model->id)
                ->exists();
            if (! $others) {
                throw ValidationException::withMessages(['domain' => 'Desactiva el SSO antes de quitar el último dominio verificado.']);
            }
        }

        $this->domains->remove($model);

        return ApiResponse::message('Dominio eliminado.');
    }

    /**
     * Prueba de conexión: lleva a quien administra al IdP sin iniciar sesión con
     * el resultado; al volver, el SPA consulta qué llegó.
     */
    public function test(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $organization = $this->entitled();
        $connection = $this->sso->connectionOf($organization);
        if ($connection === null || ! $connection->isConfigured()) {
            throw ValidationException::withMessages(['connection' => 'Guarda primero la configuración del proveedor de identidad.']);
        }

        return ApiResponse::success([
            'redirect_url' => $this->sso->start($organization, $connection, null, $request->user()),
        ]);
    }

    public function testResult(Request $request, string $token): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $result = $this->sso->findTestResult($this->organization(), $request->user(), $token);
        abort_if($result === null, 404);

        return ApiResponse::success($result);
    }

    /** Dominio de la organización actual (nunca de otra: sin IDOR). */
    private function resolveDomain(string $publicId): OrganizationDomain
    {
        return OrganizationDomain::query()
            ->where('organization_id', $this->organization()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function entitled(): Organization
    {
        $organization = $this->organization();
        if (! $this->configuration->available($organization)) {
            throw new PlanLimitExceededException('Tu plan no incluye inicio de sesión único (SSO).', Entitlement::FEATURE_SSO);
        }

        return $organization;
    }

    private function organization(): Organization
    {
        $organization = $this->tenant->organization();
        abort_if($organization === null, 403);

        return $organization;
    }
}
