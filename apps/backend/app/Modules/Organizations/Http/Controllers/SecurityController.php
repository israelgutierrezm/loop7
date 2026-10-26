<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Http\Requests\UpdateSecurityRequest;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\TwoFactorRequirement;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controles Enterprise de la organización actual (docs/03): exigir la
 * verificación en dos pasos a todo el equipo.
 */
class SecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorRequirement $twoFactor,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ORGANIZATION_UPDATE), 403);

        return ApiResponse::success($this->present($this->organization()));
    }

    public function update(UpdateSecurityRequest $request): JsonResponse
    {
        $organization = $this->organization();
        $value = $request->boolean('require_two_factor');

        if ($organization->require_two_factor !== $value) {
            $organization->forceFill(['require_two_factor' => $value])->save();
            $this->audit->log(AuditAction::ORGANIZATION_SECURITY_UPDATED, $organization, [
                'require_two_factor' => $value,
                'members_without_two_factor' => $this->twoFactor->pendingMembers($organization),
            ]);
        }

        return ApiResponse::success(
            $this->present($organization),
            $value ? 'Ahora la organización exige la verificación en dos pasos.' : 'La verificación en dos pasos vuelve a ser opcional.',
        );
    }

    /**
     * @return array{available: bool, require_two_factor: bool, members_without_two_factor: int}
     */
    private function present(Organization $organization): array
    {
        return [
            'available' => $this->twoFactor->available($organization),
            'require_two_factor' => $organization->require_two_factor,
            'members_without_two_factor' => $this->twoFactor->pendingMembers($organization),
        ];
    }

    private function organization(): Organization
    {
        $organization = $this->tenant->organization();
        abort_if($organization === null, 403);

        return $organization;
    }
}
