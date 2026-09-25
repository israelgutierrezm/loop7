<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiResponse;
use App\Support\Security\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Salud de colas y trabajos fallidos (docs/09 Jobs/Queues). Operable por
 * SUPERADMIN: reintentar o descartar trabajos fallidos, uno a uno o todos.
 */
class PlatformJobsController extends Controller
{
    /** Colas que atiende el worker (docs/21: `queue:work --queue=…`). */
    private const QUEUES = ['publishing', 'default', 'inbox', 'analytics', 'automations'];

    private const LIST_LIMIT = 100;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => $row->uuid,
                    'queue' => $row->queue,
                    'connection' => $row->connection,
                    'name' => class_basename((string) ($payload['displayName'] ?? 'Job')),
                    'exception' => Str::limit(SecretRedactor::redact((string) $row->exception), 240),
                    'failed_at' => $row->failed_at,
                ];
            })
            ->all();

        $queues = array_map(fn (string $name) => ['name' => $name, 'size' => $this->size($name)], self::QUEUES);

        return ApiResponse::success([
            'connection' => (string) config('queue.default'),
            'queues' => $queues,
            'queued' => array_sum(array_column($queues, 'size')),
            'failed_count' => DB::table('failed_jobs')->count(),
            'failed' => $failed,
            'list_limit' => self::LIST_LIMIT,
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $id)->exists(), 404, 'El trabajo ya no está en la lista de fallidos.');

        Artisan::call('queue:retry', ['id' => [$id]]);
        $this->audit->log(AuditAction::SUPERADMIN_FAILED_JOBS_RETRIED, properties: ['job' => $id, 'count' => 1]);

        return ApiResponse::message('Trabajo reencolado.');
    }

    public function retryAll(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        if ($count > 0) {
            Artisan::call('queue:retry', ['id' => ['all']]);
            $this->audit->log(AuditAction::SUPERADMIN_FAILED_JOBS_RETRIED, properties: ['job' => 'all', 'count' => $count]);
        }

        return ApiResponse::message($count === 1 ? '1 trabajo reencolado.' : "{$count} trabajos reencolados.");
    }

    public function forget(string $id): JsonResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $id)->delete();
        abort_if($deleted === 0, 404, 'El trabajo ya no está en la lista de fallidos.');

        $this->audit->log(AuditAction::SUPERADMIN_FAILED_JOBS_DISCARDED, properties: ['job' => $id, 'count' => 1]);

        return ApiResponse::message('Trabajo descartado.');
    }

    public function flush(): JsonResponse
    {
        $count = DB::table('failed_jobs')->delete();
        if ($count > 0) {
            $this->audit->log(AuditAction::SUPERADMIN_FAILED_JOBS_DISCARDED, properties: ['job' => 'all', 'count' => $count]);
        }

        return ApiResponse::message($count === 1 ? '1 trabajo descartado.' : "{$count} trabajos descartados.");
    }

    private function size(string $queue): ?int
    {
        try {
            return Queue::size($queue);
        } catch (Throwable) {
            return null; // backend de colas no disponible (p. ej. Redis caído)
        }
    }
}
