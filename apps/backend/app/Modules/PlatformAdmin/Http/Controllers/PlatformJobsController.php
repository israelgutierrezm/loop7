<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Salud de colas y trabajos fallidos (docs/09 Jobs/Queues). Operable por
 * SUPERADMIN: reintentar o descartar trabajos fallidos.
 */
class PlatformJobsController extends Controller
{
    public function index(): JsonResponse
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => $row->uuid,
                    'queue' => $row->queue,
                    'connection' => $row->connection,
                    'name' => $payload['displayName'] ?? 'Job',
                    'exception' => Str::limit((string) $row->exception, 240),
                    'failed_at' => $row->failed_at,
                ];
            })
            ->all();

        return ApiResponse::success([
            'queued' => DB::table('jobs')->count(),
            'failed_count' => DB::table('failed_jobs')->count(),
            'failed' => $failed,
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        return ApiResponse::message('Trabajo reencolado.');
    }

    public function forget(string $id): JsonResponse
    {
        DB::table('failed_jobs')->where('uuid', $id)->delete();

        return ApiResponse::message('Trabajo descartado.');
    }
}
