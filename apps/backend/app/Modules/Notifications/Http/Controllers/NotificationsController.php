<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Avisos in-app del usuario autenticado en la Organization actual. Sólo se
 * accede a los propios (se resuelven desde la relación del usuario).
 */
class NotificationsController extends Controller
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));

        $items = $this->query($request)
            ->when($request->boolean('unread'), fn (Builder $q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate($perPage)
            ->through(fn (DatabaseNotification $n) => $this->present($n));

        return ApiResponse::paginated($items, meta: ['unread' => $this->unread($request)]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $this->unread($request)]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $model = $this->query($request)->whereKey($notification)->firstOrFail();
        $model->markAsRead();

        return ApiResponse::success($this->present($model), meta: ['unread' => $this->unread($request)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['unread' => 0], 'Todo marcado como leído.');
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function query(Request $request): Builder
    {
        /** @var Builder<DatabaseNotification> $query */
        $query = $request->user()->notifications()->getQuery();

        return $query->where('organization_id', $this->tenant->organizationId());
    }

    private function unread(Request $request): int
    {
        return $this->query($request)->whereNull('read_at')->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $notification->getAttribute('data');
        /** @var \Illuminate\Support\Carbon|null $readAt */
        $readAt = $notification->getAttribute('read_at');
        /** @var \Illuminate\Support\Carbon|null $createdAt */
        $createdAt = $notification->getAttribute('created_at');

        return [
            'id' => $notification->getKey(),
            'kind' => $notification->getAttribute('type'),
            'category' => $data['category'] ?? null,
            'level' => $data['level'] ?? 'info',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'path' => $data['path'] ?? null,
            'read_at' => $readAt?->toIso8601String(),
            'created_at' => $createdAt?->toIso8601String(),
        ];
    }
}
