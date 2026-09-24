<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Ai\Enums\AiOperation;
use App\Modules\Ai\Services\AiGenerationService;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Inbox\Enums\ConversationStatus;
use App\Modules\Inbox\Events\ConversationAssigned;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Services\InboxService;
use App\Modules\Organizations\Services\MembershipService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Inbox unificado (docs/05). Requiere permiso social_accounts.inbox y el feature
 * de plan feature.inbox. Todo se acota a la Organization (anti-IDOR).
 */
class InboxController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly InboxService $inbox,
        private readonly EntitlementsService $entitlements,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly MembershipService $memberships,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $this->authorizeInbox($request);

        $query = InboxConversation::query()
            ->where('brand_id', $brandModel->id)
            ->with('assignee:id,public_id,name')
            ->orderByDesc('last_message_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->boolean('assigned_to_me')) {
            $query->where('assigned_to_user_id', $request->user()->id);
        }

        $items = $query->paginate((int) $request->integer('per_page', 30))
            ->through(fn (InboxConversation $c) => $this->presentConversation($c));

        return ApiResponse::paginated($items);
    }

    public function sync(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $this->authorizeInbox($request);

        $counts = $this->inbox->syncBrand($brandModel);

        return ApiResponse::success($counts, 'Inbox actualizado.');
    }

    /**
     * Miembros a los que se puede asignar una conversación de la marca.
     */
    public function assignees(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $this->authorizeInbox($request);

        return ApiResponse::success($this->assignableMembers($brandModel->id)
            ->map(fn (User $u) => ['id' => $u->public_id, 'name' => $u->name])
            ->values()
            ->all());
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $model->loadMissing('assignee:id,public_id,name');
        $messages = $model->messages()->orderBy('sent_at')->get()
            ->map(fn (InboxMessage $m) => $this->presentMessage($m))->all();

        // Marcar como leída al abrir.
        if ($model->unread_count > 0) {
            $model->update(['unread_count' => 0]);
        }

        return ApiResponse::success([
            ...$this->presentConversation($model),
            'messages' => $messages,
        ]);
    }

    public function reply(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $message = $this->inbox->reply($model, $request->user(), $data['body']);

        $this->audit->log(AuditAction::INBOX_REPLIED, $model, ['conversation' => $model->public_id]);

        return ApiResponse::success($this->presentMessage($message), 'Respuesta enviada.', status: 201);
    }

    public function note(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $message = $this->inbox->addNote($model, $request->user(), $data['body']);

        return ApiResponse::success($this->presentMessage($message), 'Nota añadida.', status: 201);
    }

    public function assign(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $data = $request->validate(['assignee' => ['nullable', 'string']]);

        $member = null;
        if (! empty($data['assignee'])) {
            // Sólo a quien puede trabajar el inbox de esta marca.
            $member = $this->assignableMembers($model->brand_id)->firstWhere('public_id', $data['assignee']);
            abort_if($member === null, 422, 'Ese miembro no tiene acceso al inbox de esta marca.');
        }

        $previous = $model->assigned_to_user_id;
        $model->update(['assigned_to_user_id' => $member?->id]);
        $this->audit->log(AuditAction::INBOX_ASSIGNED, $model, ['assignee' => $data['assignee'] ?? null]);

        if ($member !== null && $member->id !== $previous) {
            ConversationAssigned::dispatch($model, $member, $request->user());
        }

        return ApiResponse::success($this->presentConversation($model->fresh()->load('assignee:id,public_id,name')), 'Asignación actualizada.');
    }

    public function updateStatus(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $data = $request->validate(['status' => ['required', Rule::in(ConversationStatus::values())]]);
        $model->update(['status' => $data['status']]);
        $this->audit->log(AuditAction::INBOX_STATUS_CHANGED, $model, ['status' => $data['status']]);

        return ApiResponse::success($this->presentConversation($model), 'Estado actualizado.');
    }

    public function tags(Request $request, string $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);

        $data = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);
        $model->update(['tags' => array_values(array_unique($data['tags']))]);

        return ApiResponse::success($this->presentConversation($model), 'Etiquetas actualizadas.');
    }

    public function suggest(Request $request, string $conversation, AiGenerationService $ai): JsonResponse
    {
        $model = $this->resolve($conversation);
        $this->authorizeInbox($request);
        abort_unless($request->user()->can('ai.generate_text'), 403);

        $lastInbound = $model->messages()->where('type', 'inbound')->orderByDesc('sent_at')->first();
        /** @var Brand $brand resuelta y autorizada en resolve() */
        $brand = $model->brand;

        $prompt = 'Un usuario escribió en ' . $model->provider . ': "' . ($lastInbound->body ?? $model->preview ?? '') . '". '
            . 'Redacta una respuesta breve, cordial y útil como la marca. No incluyas comillas.';

        $result = $ai->generateText($brand, $request->user(), $prompt, AiOperation::SUGGEST_REPLY, $model->provider);

        return ApiResponse::success([
            'suggestion' => $result['text'],
            'credits' => $result['credits'],
            'remaining' => $result['remaining'],
        ]);
    }

    private function resolve(string $publicId): InboxConversation
    {
        $conversation = InboxConversation::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($conversation->brand);

        return $conversation;
    }

    /**
     * @return Collection<int, User>
     */
    private function assignableMembers(int $brandId): Collection
    {
        $organization = $this->tenant->organization();

        return $organization === null
            ? new Collection()
            : $this->memberships->membersWithPermission($organization, Permission::SOCIAL_ACCOUNTS_INBOX, $brandId);
    }

    private function authorizeInbox(Request $request): void
    {
        abort_unless($request->user()->can('social_accounts.inbox'), 403);

        $organization = $this->tenant->organization();
        if ($organization === null || ! $this->entitlements->allows($organization, Entitlement::FEATURE_INBOX)) {
            throw new PlanLimitExceededException('Tu plan no incluye el Inbox.', Entitlement::FEATURE_INBOX);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversation(InboxConversation $c): array
    {
        $c->loadMissing('assignee:id,public_id,name'); // en listados ya viene precargado

        return [
            'id' => $c->public_id,
            'type' => $c->type,
            'provider' => $c->provider,
            'participant' => $c->participant_name,
            'preview' => $c->preview,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'assignee' => $c->assignee?->name,
            'assignee_id' => $c->assignee?->public_id,
            'unread' => $c->unread_count,
            'tags' => $c->tags ?? [],
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(InboxMessage $m): array
    {
        return [
            'id' => $m->public_id,
            'type' => $m->type->value,
            'author' => $m->author_name,
            'body' => $m->body,
            'sent_at' => $m->sent_at?->toIso8601String(),
        ];
    }
}
