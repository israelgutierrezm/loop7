<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Models\DataDeletionRequest;
use App\Modules\Compliance\Services\DataDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Endpoints públicos de borrado de datos (Meta App Review, docs/19). El callback
 * recibe el signed_request firmado; la página de estado permite al usuario
 * consultar su solicitud con el código de confirmación.
 */
class DataDeletionController extends Controller
{
    public function __construct(private readonly DataDeletionService $service)
    {
    }

    /**
     * Callback de borrado de datos de Meta. Devuelve el formato que exige Meta:
     * { "url": <estado>, "confirmation_code": <código> }.
     */
    public function facebook(Request $request): JsonResponse
    {
        $signed = (string) $request->input('signed_request', '');
        if ($signed === '') {
            return response()->json(['error' => 'Falta signed_request.'], 400);
        }

        try {
            $deletion = $this->service->handleSignedRequest('facebook', $signed);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $statusUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/eliminar-datos?code=' . $deletion->confirmation_code;

        return response()->json([
            'url' => $statusUrl,
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    /**
     * Estado de una solicitud por su código de confirmación (página pública).
     */
    public function status(string $code): JsonResponse
    {
        $deletion = DataDeletionRequest::query()->where('confirmation_code', $code)->first();

        if ($deletion === null) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        return response()->json([
            'confirmation_code' => $deletion->confirmation_code,
            'provider' => $deletion->provider,
            'status' => $deletion->status,
            'requested_at' => $deletion->created_at?->toIso8601String(),
            'completed_at' => $deletion->completed_at?->toIso8601String(),
        ]);
    }
}
