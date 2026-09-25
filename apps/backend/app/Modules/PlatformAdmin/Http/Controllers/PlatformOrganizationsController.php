<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOrganizationsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()->withCount(['users', 'brands'])->latest()->latest('id');

        if ($request->filled('q')) {
            $term = '%' . addcslashes($request->string('q')->trim()->toString(), '%_\\') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhere('billing_email', 'like', $term));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $organizations = $query->paginate(min(100, max(1, (int) $request->integer('per_page', 20))))
            ->through(fn (Organization $o) => $this->present($o));

        return ApiResponse::paginated($organizations);
    }

    public function show(string $organization): JsonResponse
    {
        $model = Organization::query()->withCount(['users', 'brands'])->where('public_id', $organization)->firstOrFail();

        return ApiResponse::success($this->present($model));
    }

    public function suspend(Request $request, string $organization): JsonResponse
    {
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $model->update(['status' => OrganizationStatus::SUSPENDED->value]);

        $this->audit->log(
            AuditAction::SUPERADMIN_ORGANIZATION_SUSPENDED,
            $model,
            ['name' => $model->name],
            organizationId: $model->id,
        );

        return ApiResponse::success($this->present($model), 'Organización suspendida.');
    }

    public function activate(string $organization): JsonResponse
    {
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $model->update(['status' => OrganizationStatus::ACTIVE->value]);

        return ApiResponse::success($this->present($model), 'Organización reactivada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->public_id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
            'billing_email' => $organization->billing_email,
            'members_count' => $organization->users_count ?? null,
            'brands_count' => $organization->brands_count ?? null,
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }
}
