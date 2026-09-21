<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Formato de respuesta consistente para toda la API v1.
 *
 * Éxito: { "data": ..., "meta": {...}, "message": string|null }
 * Error: { "message": string, "code": string, "errors": {...} }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        if ($data instanceof AbstractPaginator) {
            return self::paginated($data, $message, $meta, $status);
        }

        return response()->json([
            'data' => self::normalize($data),
            'meta' => (object) $meta,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(
        AbstractPaginator $paginator,
        ?string $message = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        $data = $paginator->getCollection()
            ->map(fn ($item) => self::normalize($item))
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
                'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
            ], $meta),
            'message' => $message,
        ], $status);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        string $message,
        string $code = 'error',
        array $errors = [],
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
        ], $status);
    }

    private static function normalize(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data;
    }
}
