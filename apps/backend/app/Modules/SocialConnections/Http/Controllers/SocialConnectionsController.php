<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialConnectionsController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly SocialConnectionService $service,
        private readonly SocialProviderManager $manager,
    ) {
    }

    /**
     * Proveedores disponibles (habilitados) con sus capacidades.
     */
    public function providers(string $brand): JsonResponse
    {
        $this->resolveBrand($brand);

        $providers = $this->manager->enabled()
            ->map(fn ($p) => [
                'key' => $p->key,
                'name' => $p->name,
                'capabilities' => $this->manager->adapter($p->key)?->capabilities() ?? [],
            ])
            ->all();

        return ApiResponse::success($providers);
    }

    public function index(string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        $connections = SocialConnection::query()
            ->where('brand_id', $brandModel->id)
            ->with('destinations')
            ->latest()
            ->get()
            ->map(fn (SocialConnection $c) => $this->present($c))
            ->all();

        return ApiResponse::success($connections);
    }

    public function connect(Request $request, string $brand, string $provider): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('social_accounts.connect'), 403);

        $url = $this->service->authorize($brandModel, $provider, $request->user());

        return ApiResponse::success(['authorize_url' => $url]);
    }

    /**
     * Conexión manual: captura un token a mano (sin OAuth) y crea la conexión.
     */
    public function connectManual(Request $request, string $brand, string $provider): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('social_accounts.connect'), 403);

        $data = $request->validate([
            'external_account_name' => ['required', 'string', 'max:120'],
            'external_account_id' => ['nullable', 'string', 'max:120'],
            'access_token' => ['required', 'string', 'max:4000'],
            'refresh_token' => ['nullable', 'string', 'max:4000'],
            'token_expires_at' => ['nullable', 'date'],
            'destinations' => ['nullable', 'array', 'max:50'],
            'destinations.*.external_id' => ['required_with:destinations', 'string', 'max:120'],
            'destinations.*.name' => ['required_with:destinations', 'string', 'max:120'],
            'destinations.*.type' => ['nullable', 'string', 'max:32'],
        ]);

        $connection = $this->service->connectManually($brandModel, $provider, $request->user(), $data);

        return ApiResponse::success($this->present($connection->load('destinations')), 'Conexión creada.', status: 201);
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        /** @var SocialConnection $model */
        $model = SocialConnection::query()->where('public_id', $connection)->firstOrFail();
        $this->resolveBrand($model->brand->public_id); // valida acceso a la Brand
        abort_unless($request->user()->can('social_accounts.disconnect'), 403);

        $this->service->disconnect($model);

        return ApiResponse::message('Conexión eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialConnection $connection): array
    {
        return [
            'id' => $connection->public_id,
            'provider' => $connection->provider,
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'needs_attention' => $connection->status->needsAttention(),
            'account_name' => $connection->external_account_name,
            'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            'destinations' => $connection->destinations->map(fn ($d) => [
                'id' => $d->public_id,
                'external_id' => $d->external_id,
                'name' => $d->name,
                'type' => $d->type,
            ])->all(),
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
