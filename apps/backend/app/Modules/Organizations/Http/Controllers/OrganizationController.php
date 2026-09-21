<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Actions\CreateOrganizationForUser;
use App\Modules\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Organizations del usuario autenticado (con sus roles en cada una).
     */
    public function index(Request $request, MembershipService $memberships): JsonResponse
    {
        $organizations = $memberships->organizationsWithRoles($request->user());

        return ApiResponse::success(
            $organizations->map(fn ($o) => (new OrganizationResource($o))->toArray($request))->all(),
        );
    }

    public function store(Request $request, CreateOrganizationForUser $create): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $organization = $create->handle($request->user(), $data['name'], $data);

        return ApiResponse::success(
            new OrganizationResource($organization),
            'Organización creada.',
            status: 201,
        );
    }

    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('view', $organization);

        return ApiResponse::success(new OrganizationResource($organization));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('update', $organization);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'nullable', 'email'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'locale' => ['sometimes', 'required', 'string', 'in:es,en'],
        ]);

        $organization->fill($data)->save();
        $this->audit->log(AuditAction::ORGANIZATION_UPDATED, $organization, ['changes' => array_keys($data)]);

        return ApiResponse::success(new OrganizationResource($organization), 'Organización actualizada.');
    }

    public function destroy(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('delete', $organization);

        $this->audit->log(AuditAction::ORGANIZATION_DELETED, $organization, [
            'name' => $organization->name,
        ]);
        $organization->delete();

        return ApiResponse::message('Organización eliminada.');
    }
}
