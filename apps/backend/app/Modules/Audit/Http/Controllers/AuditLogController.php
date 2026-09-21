<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();

        $query = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,public_id,name,email')
            ->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        $logs = $query->paginate((int) $request->integer('per_page', 30))
            ->through(fn (AuditLog $log) => [
                'id' => $log->public_id,
                'action' => $log->action,
                'description' => $log->description,
                'properties' => $log->properties,
                'actor' => $log->user ? [
                    'id' => $log->user->public_id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated($logs);
    }
}
