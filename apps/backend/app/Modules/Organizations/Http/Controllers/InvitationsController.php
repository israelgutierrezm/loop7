<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Actions\AcceptInvitation;
use App\Modules\Organizations\Actions\InviteMember;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorize('viewMembers', $context->organization());

        $invitations = OrganizationInvitation::query()
            ->latest()
            ->get()
            ->map(fn (OrganizationInvitation $i) => $this->present($i))
            ->all();

        return ApiResponse::success($invitations);
    }

    public function store(Request $request, InviteMember $invite, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('invite', $organization);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'string'],
        ]);

        $invitation = $invite->handle($organization, $data['email'], $data['role'], $request->user());

        return ApiResponse::success($this->present($invitation), 'Invitación enviada.', status: 201);
    }

    public function destroy(Request $request, string $invitation, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $this->authorize('invite', $organization);

        /** @var OrganizationInvitation|null $model */
        $model = OrganizationInvitation::query()->where('public_id', $invitation)->first();

        if ($model === null) {
            return ApiResponse::error('Invitación no encontrada.', 'not_found', status: 404);
        }

        $model->update(['status' => InvitationStatus::REVOKED->value]);
        $this->audit->log(AuditAction::MEMBER_INVITATION_REVOKED, $model, ['email' => $model->email]);

        return ApiResponse::message('Invitación revocada.');
    }

    /**
     * Aceptación de invitación por el usuario autenticado (sin contexto de tenant).
     */
    public function accept(Request $request, AcceptInvitation $accept): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $organization = $accept->handle($data['token'], $request->user());

        return ApiResponse::success(
            new OrganizationResource($organization),
            'Te uniste a la organización.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status->value,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
