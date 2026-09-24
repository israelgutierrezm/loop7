<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Enums\InvitationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumen de inicio de la Organization. Cada bloque se incluye sólo si el rol
 * puede verlo y el contenido se limita a las Brands a las que el usuario
 * tiene acceso (Brand Access).
 */
class DashboardController extends Controller
{
    /** @var list<int>|null null = acceso a todas las Brands */
    private ?array $brandIds = null;

    public function __invoke(Request $request, TenantContext $tenant, BrandAccess $access, UsageService $usage): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $tenant->organization();
        $this->brandIds = $access->restrictedBrandIds($user);

        $data = [
            'onboarding' => $this->onboarding($organization),
            'usage' => $usage->current($organization),
        ];

        if ($user->can(Permission::CONTENT_VIEW)) {
            $data['content'] = $this->content();
        }

        if ($user->can(Permission::SOCIAL_ACCOUNTS_VIEW)) {
            $connections = $this->scoped(SocialConnection::query());
            $data['social'] = [
                'connected' => (clone $connections)->where('status', ConnectionStatus::CONNECTED->value)->count(),
                'needs_attention' => (clone $connections)->where('status', '!=', ConnectionStatus::CONNECTED->value)->count(),
            ];
        }

        if ($user->can(Permission::MEMBERS_VIEW)) {
            $data['team'] = [
                'members' => $organization->users()->wherePivot('status', 'active')->count(),
                'pending_invitations' => OrganizationInvitation::query()
                    ->where('status', InvitationStatus::PENDING->value)
                    ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
            ];
        }

        return ApiResponse::success($data);
    }

    /**
     * Pasos de puesta en marcha (a nivel de Organization, no de rol).
     *
     * @return array<string, bool>
     */
    private function onboarding(Organization $organization): array
    {
        return [
            'has_brand' => Brand::query()->exists(),
            'has_connection' => SocialConnection::query()->exists(),
            'has_content' => ContentItem::query()->exists(),
            'has_published' => PublicationTarget::query()->where('status', TargetStatus::PUBLISHED->value)->exists(),
            'has_team' => $organization->users()->count() > 1
                || OrganizationInvitation::query()->where('status', InvitationStatus::PENDING->value)->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $items = fn (): Builder => $this->scoped(ContentItem::query());
        $targets = fn (): Builder => PublicationTarget::query()->when(
            $this->brandIds !== null,
            fn (Builder $q) => $q->whereHas('variant.contentItem', fn (Builder $c) => $c->whereIn('brand_id', $this->brandIds ?? [])),
        );

        $upcoming = $items()
            ->where('status', ContentStatus::SCHEDULED->value)
            ->where('scheduled_at', '>=', now())
            ->with(['brand:id,public_id,name', 'variants:id,content_item_id,provider'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get()
            ->map(fn (ContentItem $c) => [
                'id' => $c->public_id,
                'title' => $c->title,
                'brand' => $c->brand?->name,
                'scheduled_at' => $c->scheduled_at?->toIso8601String(),
                'providers' => $c->variants->map(fn (PostVariant $v) => $v->provider)->unique()->values()->all(),
            ])->all();

        $attention = $items()
            ->whereIn('status', [
                ContentStatus::FAILED->value, ContentStatus::PARTIAL->value, ContentStatus::CHANGES_REQUESTED->value,
            ])
            ->with('brand:id,public_id,name')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ContentItem $c) => [
                'id' => $c->public_id,
                'title' => $c->title,
                'brand' => $c->brand?->name,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])->all();

        return [
            'in_review' => $items()->where('status', ContentStatus::IN_REVIEW->value)->count(),
            'ready' => $items()->where('status', ContentStatus::APPROVED->value)->count(),
            'scheduled_next_7_days' => $items()
                ->where('status', ContentStatus::SCHEDULED->value)
                ->whereBetween('scheduled_at', [now(), now()->addDays(7)])
                ->count(),
            'published_7_days' => $targets()
                ->where('status', TargetStatus::PUBLISHED->value)
                ->where('published_at', '>=', now()->subDays(7))
                ->count(),
            'failed_7_days' => $targets()
                ->where('status', TargetStatus::FAILED->value)
                ->where('updated_at', '>=', now()->subDays(7))
                ->count(),
            'upcoming' => $upcoming,
            'attention' => $attention,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query): Builder
    {
        return $this->brandIds === null ? $query : $query->whereIn('brand_id', $this->brandIds);
    }
}
