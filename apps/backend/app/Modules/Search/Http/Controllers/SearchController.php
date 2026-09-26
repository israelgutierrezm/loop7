<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Services\BrandAccess;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Content\Models\ContentItem;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda del buscador de comandos (Ctrl/⌘+K): contenido, marcas, campañas y
 * miembros de la Organization actual. Cada tipo exige su permiso de lectura y
 * respeta el acceso por marca (docs/03): nunca devuelve nada de otra
 * organización ni de marcas a las que el usuario no accede.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request, BrandAccess $access, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = '%' . addcslashes(trim($data['q']), '%_\\') . '%';
        /** @var User $user */
        $user = $request->user();
        $restricted = $access->restrictedBrandIds($user); // null = todas las marcas

        $results = [];

        if ($user->can(Permission::CONTENT_VIEW)) {
            $results['content'] = ContentItem::query()
                ->with('brand:id,public_id,name')
                ->when($restricted !== null, fn ($q) => $q->whereIn('brand_id', $restricted))
                ->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('body', 'like', $term))
                ->latest()
                ->latest('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (ContentItem $c) => [
                    'id' => $c->public_id,
                    'title' => $c->title,
                    'subtitle' => trim(($c->brand->name ?? '') . ' · ' . $c->status->label(), ' ·'),
                    'url' => '/app/content/' . $c->public_id,
                ])->all();
        }

        if ($user->can(Permission::BRANDS_VIEW)) {
            $results['brands'] = Brand::query()
                ->when($restricted !== null, fn ($q) => $q->whereIn('id', $restricted))
                ->where('name', 'like', $term)
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'public_id', 'name', 'website'])
                ->map(fn (Brand $b) => [
                    'id' => $b->public_id,
                    'title' => $b->name,
                    'subtitle' => $b->website ?? 'Marca',
                    'url' => '/app/brands/' . $b->public_id,
                ])->all();
        }

        if ($user->can(Permission::CAMPAIGNS_VIEW)) {
            $results['campaigns'] = Campaign::query()
                ->with('brand:id,public_id,name')
                ->when($restricted !== null, fn ($q) => $q->whereIn('brand_id', $restricted))
                ->where('name', 'like', $term)
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->public_id,
                    'title' => $c->name,
                    'subtitle' => trim(($c->brand->name ?? '') . ' · ' . $c->status->label(), ' ·'),
                    'url' => '/app/campaigns?brand=' . ($c->brand->public_id ?? ''),
                ])->all();
        }

        $organization = $tenant->organization();
        if ($organization !== null && $user->can(Permission::MEMBERS_VIEW)) {
            $results['members'] = $organization->users()
                ->where(fn ($q) => $q->where('users.name', 'like', $term)->orWhere('users.email', 'like', $term))
                ->orderBy('users.name')
                ->limit(self::LIMIT)
                ->get(['users.id', 'users.public_id', 'users.name', 'users.email'])
                ->map(fn (User $u) => [
                    'id' => $u->public_id,
                    'title' => $u->name,
                    'subtitle' => $u->email,
                    'url' => '/app/team',
                ])->values()->all();
        }

        return ApiResponse::success($results);
    }
}
