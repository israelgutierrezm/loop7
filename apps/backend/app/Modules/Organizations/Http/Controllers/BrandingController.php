<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\OrganizationBranding;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marca blanca de la Organization actual (docs/08: feature.white_label).
 * Configurarla exige organization.update y que el plan la incluya.
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly OrganizationBranding $branding,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_VIEW), 403);

        return ApiResponse::success($this->branding->settings($this->organization()));
    }

    public function update(Request $request): JsonResponse
    {
        $organization = $this->authorizeChange($request);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:60'],
            'primary_color' => [
                'bail',
                'nullable',
                'regex:/^#[0-9a-fA-F]{6}$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && OrganizationBranding::contrastWithWhite($value) < OrganizationBranding::MIN_CONTRAST) {
                        $fail('Elige un color más oscuro: el texto blanco de los botones no se leería bien.');
                    }
                },
            ],
        ], [
            'primary_color.regex' => 'Usa un color hexadecimal, p. ej. #4f46e5.',
        ]);

        $this->branding->update($organization, $data['display_name'] ?? null, $data['primary_color'] ?? null);
        $this->audit->log(AuditAction::ORGANIZATION_BRANDING_UPDATED, $organization, ['changes' => ['display_name', 'primary_color']]);

        return ApiResponse::success($this->branding->settings($organization->fresh() ?? $organization), 'Marca blanca guardada.');
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $organization = $this->authorizeChange($request);

        $request->validate([
            // MIME real (finfo). Sin SVG: puede contener scripts.
            'logo' => [
                'required', 'file', 'max:1024', 'mimetypes:image/png,image/jpeg,image/webp',
                'dimensions:min_width=32,min_height=32,max_width=2000,max_height=2000',
            ],
        ], [
            'logo.max' => 'El logo no puede superar 1 MB.',
            'logo.mimetypes' => 'Usa una imagen PNG, JPG o WEBP.',
            'logo.dimensions' => 'El logo debe medir entre 32 y 2000 píxeles por lado.',
        ]);

        $this->branding->storeLogo($organization, $request->file('logo'));
        $this->audit->log(AuditAction::ORGANIZATION_BRANDING_UPDATED, $organization, ['changes' => ['logo']]);

        return ApiResponse::success($this->branding->settings($organization), 'Logo actualizado.');
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $organization = $this->organization();

        $this->branding->removeLogo($organization);
        $this->audit->log(AuditAction::ORGANIZATION_BRANDING_UPDATED, $organization, ['changes' => ['logo_removed']]);

        return ApiResponse::success($this->branding->settings($organization), 'Logo quitado.');
    }

    /**
     * Sirve el logo por URL firmada (sin sesión: la firma autoriza el acceso).
     */
    public function logo(string $organization): StreamedResponse
    {
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $path = $this->branding->logoPath($model);
        $disk = Storage::disk(OrganizationBranding::DISK);

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeChange(Request $request): Organization
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);
        $organization = $this->organization();

        if (! $this->branding->enabled($organization)) {
            throw new PlanLimitExceededException('Tu plan no incluye marca blanca.', Entitlement::FEATURE_WHITE_LABEL);
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
