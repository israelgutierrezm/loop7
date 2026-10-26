<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Organizations\Services\TwoFactorRequirement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Controles de seguridad de la organización. Exigir el doble factor pide el plan
 * (feature.require_two_factor) y que quien lo activa ya lo tenga: así nunca se
 * deja fuera a sí misma.
 */
class UpdateSecurityRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! (bool) $this->user()?->can(Permission::ORGANIZATION_UPDATE)) {
            return false;
        }

        $organization = app(TenantContext::class)->organization();
        if ($organization === null || ! app(TwoFactorRequirement::class)->available($organization)) {
            throw new PlanLimitExceededException('Tu plan no incluye exigir la verificación en dos pasos.', Entitlement::FEATURE_REQUIRE_TWO_FACTOR);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'require_two_factor' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->boolean('require_two_factor') && ! $this->user()?->hasTwoFactorEnabled()) {
                    $validator->errors()->add('require_two_factor', 'Activa primero tu verificación en dos pasos en Mi perfil.');
                }
            },
        ];
    }
}
