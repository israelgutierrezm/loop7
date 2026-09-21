<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'organizations' => [
                'total' => Organization::count(),
                'active' => Organization::where('status', OrganizationStatus::ACTIVE->value)->count(),
                'suspended' => Organization::where('status', OrganizationStatus::SUSPENDED->value)->count(),
            ],
            'users' => [
                'total' => User::count(),
                'platform_admins' => User::where('is_platform_admin', true)->count(),
                'new_last_7_days' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'brands' => [
                'total' => Brand::query()->withoutGlobalScopes()->count(),
            ],
            'audit' => [
                'events_today' => AuditLog::whereDate('created_at', today())->count(),
            ],
            'recent_organizations' => Organization::latest()->take(5)->get()->map(fn (Organization $o) => [
                'id' => $o->public_id,
                'name' => $o->name,
                'status' => $o->status->value,
                'created_at' => $o->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
