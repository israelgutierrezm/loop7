<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Organizations\Actions\CreateOrganizationForUser;
use App\Modules\Organizations\Actions\DeleteOrganization;
use App\Modules\Organizations\Actions\TransferOrganizationOwnership;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\MembershipService;
use App\Modules\PlatformAdmin\Services\PlatformSettings;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request, CreateOrganizationForUser $create, PlatformSettings $settings): JsonResponse
    {
        // Con el alta cerrada (beta privada, sólo por invitación) tampoco se crean organizaciones nuevas.
        if (! $settings->bool('registration.open') && ! $request->user()->isPlatformAdmin()) {
            return ApiResponse::error(
                'El alta de organizaciones nuevas está cerrada por el momento.',
                'registration_closed',
                status: 403,
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        // Cada organización nueva estrena periodo de prueba: tope por propietario.
        $max = (int) config('platform.max_owned_organizations', 5);
        if (Organization::query()->where('owner_user_id', $request->user()->id)->count() >= $max) {
            return ApiResponse::error(
                "Ya eres propietario de {$max} organizaciones, el máximo permitido. Escribe a soporte si necesitas más.",
                'organization_limit',
                status: 422,
            );
        }

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

    /**
     * Elimina la Organization actual (sólo su propietario). Exige escribir su
     * nombre y la contraseña, y que la pasarela ya no vaya a cobrar sola.
     */
    public function destroy(
        Request $request,
        TenantContext $context,
        SubscriptionService $subscriptions,
        DeleteOrganization $delete,
    ): JsonResponse {
        $organization = $context->organization();
        $this->authorize('delete', $organization);

        $request->validate([
            'confirm_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'current_password'],
        ], ['password.current_password' => 'La contraseña no es correcta.']);

        if (mb_strtolower(trim($request->string('confirm_name')->toString())) !== mb_strtolower(trim($organization->name))) {
            throw ValidationException::withMessages(['confirm_name' => 'Escribe el nombre exacto de la organización.']);
        }

        if ($subscriptions->hasAutomaticCharge($organization)) {
            return ApiResponse::error(
                'Cancela primero la suscripción en Facturación: la pasarela seguiría cobrando.',
                'subscription_active',
                status: 409,
            );
        }

        $delete->handle($organization);

        return ApiResponse::message('Organización eliminada.');
    }

    /**
     * Transfiere la propiedad a otro miembro activo (sólo el propietario actual,
     * con su contraseña). El anterior propietario queda como ADMIN.
     */
    public function transferOwnership(
        Request $request,
        TenantContext $context,
        TransferOrganizationOwnership $transfer,
    ): JsonResponse {
        $organization = $context->organization();
        $this->authorize('transferOwnership', $organization);

        $data = $request->validate([
            'user' => ['required', 'string'],
            'password' => ['required', 'current_password'],
        ], ['password.current_password' => 'La contraseña no es correcta.']);

        /** @var User|null $target */
        $target = $organization->users()
            ->where('users.public_id', $data['user'])
            ->wherePivot('status', MembershipStatus::ACTIVE->value)
            ->first();

        if ($target === null || $target->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'Elige a otro miembro activo de la organización.']);
        }
        if ($target->isBlocked()) {
            throw ValidationException::withMessages(['user' => 'Esa cuenta está bloqueada.']);
        }

        $transfer->handle($organization, $request->user(), $target);

        return ApiResponse::message("{$target->name} es ahora el propietario de la organización.");
    }
}
