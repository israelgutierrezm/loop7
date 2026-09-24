<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auditoría global de la plataforma (SUPERADMIN): acciones de todas las
 * organizaciones y de la propia administración, con filtros.
 */
class PlatformAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with(['user:id,public_id,name,email', 'organization:id,public_id,name'])
            ->latest('id');

        if ($request->filled('action')) {
            // Prefijo: "subscription." filtra todo el grupo; una acción exacta también sirve.
            $query->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $request->string('action')->toString()) . '%');
        }
        if ($request->filled('organization')) {
            $query->whereHas('organization', fn ($q) => $q->where('public_id', $request->string('organization')->toString()));
        }
        if ($request->boolean('platform_only')) {
            $query->whereNull('organization_id');
        }
        if ($request->filled('search')) {
            $term = '%' . $request->string('search')->toString() . '%';
            $query->whereHas('user', fn ($q) => $q->where('email', 'like', $term)->orWhere('name', 'like', $term));
        }

        $logs = $query->paginate(min(100, max(10, (int) $request->integer('per_page', 30))))
            ->through(fn (AuditLog $log) => [
                'id' => $log->public_id,
                'action' => $log->action,
                'properties' => $log->properties,
                'organization' => $log->organization ? ['id' => $log->organization->public_id, 'name' => $log->organization->name] : null,
                'actor' => $log->user ? ['name' => $log->user->name, 'email' => $log->user->email] : null,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated($logs);
    }
}
