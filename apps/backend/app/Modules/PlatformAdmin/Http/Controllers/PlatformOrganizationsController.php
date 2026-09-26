<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Actions\DeleteOrganization;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organizaciones de la plataforma (SUPERADMIN): listado, ficha de soporte con
 * miembros y marcas, suspensión (bloquea todo el acceso y la operación
 * programada) y eliminación a petición del cliente.
 */
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

        return ApiResponse::success([
            ...$this->present($model),
            'members' => $this->members($model),
            'brands' => $this->brands($model),
        ]);
    }

    public function suspend(Request $request, string $organization): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $model->update(['status' => OrganizationStatus::SUSPENDED->value]);

        $this->audit->log(
            AuditAction::SUPERADMIN_ORGANIZATION_SUSPENDED,
            $model,
            ['name' => $model->name, 'reason' => $data['reason'] ?? null],
            organizationId: $model->id,
        );

        return ApiResponse::success($this->present($model), 'Organización suspendida: su equipo pierde el acceso y no se publica nada.');
    }

    public function activate(string $organization): JsonResponse
    {
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $model->update(['status' => OrganizationStatus::ACTIVE->value]);

        $this->audit->log(AuditAction::SUPERADMIN_ORGANIZATION_ACTIVATED, $model, ['name' => $model->name], organizationId: $model->id);

        return ApiResponse::success($this->present($model), 'Organización reactivada.');
    }

    /**
     * Eliminación a petición del cliente (p. ej. perdió el acceso de su
     * propietario). Misma limpieza que cuando la elimina su propietario.
     */
    public function destroy(
        Request $request,
        string $organization,
        SubscriptionService $subscriptions,
        DeleteOrganization $delete,
    ): JsonResponse {
        $model = Organization::query()->where('public_id', $organization)->firstOrFail();
        $request->validate(['confirm_name' => ['required', 'string', 'max:255']]);

        if (mb_strtolower(trim($request->string('confirm_name')->toString())) !== mb_strtolower(trim($model->name))) {
            throw ValidationException::withMessages(['confirm_name' => 'Escribe el nombre exacto de la organización.']);
        }
        if ($subscriptions->hasAutomaticCharge($model)) {
            return ApiResponse::error(
                'La pasarela sigue cobrando esta suscripción: cancélala primero (sección Suscripción).',
                'subscription_active',
                status: 409,
            );
        }

        $delete->handle($model);

        return ApiResponse::message('Organización eliminada.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(Organization $organization): array
    {
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.organization_id', $organization->id)
            ->where('model_has_roles.model_type', (new User())->getMorphClass())
            ->pluck('roles.name', 'model_has_roles.model_id');

        return $organization->users()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $roles[$u->id] ?? null,
                'status' => $u->pivot->status,
                'is_owner' => $u->id === $organization->owner_user_id,
                'blocked' => $u->isBlocked(),
                'is_platform_admin' => (bool) $u->is_platform_admin,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function brands(Organization $organization): array
    {
        $brands = Brand::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'created_at']);

        $connections = DB::table('social_connections')
            ->whereIn('brand_id', $brands->pluck('id'))
            ->whereNull('deleted_at')
            ->groupBy('brand_id')
            ->pluck(DB::raw('count(*)'), 'brand_id');

        return $brands->map(fn (Brand $b) => [
            'id' => $b->public_id,
            'name' => $b->name,
            'connections_count' => (int) ($connections[$b->id] ?? 0),
            'created_at' => $b->created_at?->toIso8601String(),
        ])->values()->all();
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
