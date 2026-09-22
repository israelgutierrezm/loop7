<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Api\Models\ApiKey;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Introspección de la API key actual: organización y scopes concedidos.
 */
class PublicMeController extends Controller
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $organization = $this->tenant->organization();

        return ApiResponse::success([
            'organization' => [
                'id' => $organization?->public_id,
                'name' => $organization?->name,
            ],
            'key' => [
                'name' => $key->name,
                'scopes' => $key->scopes,
            ],
        ]);
    }
}
