<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformUsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->withCount('organizations')->latest();

        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }

        $users = $query->paginate((int) $request->integer('per_page', 20))
            ->through(fn (User $u) => [
                'id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'is_platform_admin' => (bool) $u->is_platform_admin,
                'two_factor_enabled' => $u->hasTwoFactorEnabled(),
                'email_verified' => $u->email_verified_at !== null,
                'organizations_count' => $u->organizations_count ?? null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated($users);
    }
}
