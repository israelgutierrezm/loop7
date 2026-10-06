<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Http\Requests\AddCompetitorAccountRequest;
use App\Modules\Competitors\Http\Requests\BenchmarkRequest;
use App\Modules\Competitors\Http\Requests\StoreCompetitorRequest;
use App\Modules\Competitors\Models\Competitor;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Models\CompetitorSnapshot;
use App\Modules\Competitors\Services\CompetitorBenchmark;
use App\Modules\Competitors\Services\CompetitorService;
use App\Modules\Competitors\Services\CompetitorSources;
use App\Modules\Competitors\Services\CompetitorSync;
use App\Modules\Organizations\Models\Organization;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Competencia de una marca (docs/05): competidores, sus cuentas y la
 * comparación con la marca. Ver exige `analytics.view`; gestionar,
 * `analytics.competitors`. Todo se resuelve dentro de la marca (sin IDOR).
 */
class CompetitorController extends Controller
{
    use ResolvesBrand;

    public function __construct(
        private readonly CompetitorService $competitors,
        private readonly CompetitorSources $sources,
    ) {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_VIEW), 403);
        $brandModel = $this->resolveBrand($brand);

        $items = Competitor::query()
            ->where('brand_id', $brandModel->id)
            ->with('accounts')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'competitors' => $items->map(fn (Competitor $c) => $this->present($c))->all(),
            'sources' => $this->sources->forBrand($brandModel),
            'usage' => $this->competitors->usage(Organization::query()->findOrFail($brandModel->organization_id)),
        ]);
    }

    public function benchmark(BenchmarkRequest $request, string $brand, CompetitorBenchmark $benchmark): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);

        return ApiResponse::success($benchmark->build($brandModel, $request->days(), $request->provider()));
    }

    public function store(StoreCompetitorRequest $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        $competitor = $this->competitors->create($brandModel, (string) $request->validated('name'), $request->accounts(), $request->user());

        return ApiResponse::success($this->present($competitor->load('accounts')), 'Competidor añadido.', status: 201);
    }

    public function update(Request $request, string $brand, string $competitor): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_COMPETITORS), 403);
        $model = $this->resolve($this->resolveBrand($brand), $competitor);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $this->competitors->rename($model, $data['name']);

        return ApiResponse::success($this->present($model->load('accounts')), 'Competidor actualizado.');
    }

    public function destroy(Request $request, string $brand, string $competitor): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_COMPETITORS), 403);
        $this->competitors->delete($this->resolve($this->resolveBrand($brand), $competitor));

        return ApiResponse::message('Competidor eliminado.');
    }

    public function addAccount(AddCompetitorAccountRequest $request, string $brand, string $competitor): JsonResponse
    {
        $model = $this->resolve($this->resolveBrand($brand), $competitor);
        $this->competitors->addAccount($model, (string) $request->validated('provider'), (string) $request->validated('handle'));

        return ApiResponse::success($this->present($model->load('accounts')), 'Cuenta añadida.', status: 201);
    }

    public function removeAccount(Request $request, string $brand, string $competitor, string $account): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_COMPETITORS), 403);
        $model = $this->resolve($this->resolveBrand($brand), $competitor);
        $this->competitors->removeAccount($model->accounts()->where('public_id', $account)->firstOrFail());

        return ApiResponse::success($this->present($model->load('accounts')), 'Cuenta quitada.');
    }

    /**
     * Actualizar ahora las cuentas del competidor (además de la foto diaria).
     */
    public function sync(Request $request, string $brand, string $competitor, CompetitorSync $sync): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ANALYTICS_COMPETITORS), 403);
        $model = $this->resolve($this->resolveBrand($brand), $competitor);

        $failed = 0;
        foreach ($model->accounts as $account) {
            $failed += $sync->sync($account) ? 0 : 1;
        }

        return ApiResponse::success(
            $this->present($model->load('accounts')),
            $failed === 0 ? 'Datos actualizados.' : "No se pudieron actualizar {$failed} cuenta(s): revisa el motivo en cada una.",
        );
    }

    private function resolve(Brand $brand, string $publicId): Competitor
    {
        return Competitor::query()->where('brand_id', $brand->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Competitor $competitor): array
    {
        return [
            'id' => $competitor->public_id,
            'name' => $competitor->name,
            'accounts' => $competitor->accounts->map(function (CompetitorAccount $a): array {
                $latest = CompetitorSnapshot::query()->where('competitor_account_id', $a->id)->orderByDesc('date')->first();

                return [
                    'id' => $a->public_id,
                    'provider' => $a->provider,
                    'handle' => $a->handle,
                    'display_name' => $a->display_name,
                    'avatar_url' => $a->avatar_url,
                    'profile_url' => $a->profile_url,
                    'status' => $a->status,
                    'last_error' => $a->last_error,
                    'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                    'followers' => $latest?->followers,
                ];
            })->all(),
        ];
    }
}
