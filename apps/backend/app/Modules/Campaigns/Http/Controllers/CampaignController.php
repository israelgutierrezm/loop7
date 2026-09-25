<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    use ResolvesBrand;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('campaigns.view'), 403);

        $campaigns = Campaign::query()
            ->where('brand_id', $brandModel->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->withCount('contentItems')
            ->latest()
            ->get()
            ->map(fn (Campaign $c) => $this->present($c))
            ->all();

        return ApiResponse::success($campaigns);
    }

    public function store(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('campaigns.create'), 403);

        $data = $this->validateData($request);

        $campaign = Campaign::query()->create(array_merge($data, [
            'organization_id' => $brandModel->organization_id,
            'brand_id' => $brandModel->id,
        ]));

        $this->audit->log(AuditAction::CAMPAIGN_CREATED, $campaign, ['name' => $campaign->name]);

        return ApiResponse::success($this->present($campaign->loadCount('contentItems')), 'Campaña creada.', status: 201);
    }

    public function update(Request $request, string $campaign): JsonResponse
    {
        $model = $this->resolve($campaign);
        abort_unless($request->user()->can('campaigns.update'), 403);

        $data = $this->validateData($request, false);
        $model->update($data);
        $this->audit->log(AuditAction::CAMPAIGN_UPDATED, $model, ['changes' => array_keys($data)]);

        return ApiResponse::success($this->present($model->loadCount('contentItems')), 'Campaña actualizada.');
    }

    public function destroy(Request $request, string $campaign): JsonResponse
    {
        $model = $this->resolve($campaign);
        abort_unless($request->user()->can('campaigns.delete'), 403);

        $this->audit->log(AuditAction::CAMPAIGN_DELETED, $model, ['name' => $model->name]);
        $model->delete();

        return ApiResponse::message('Campaña eliminada. Su contenido se conserva, sin campaña.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'objective' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(CampaignStatus::values())],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function resolve(string $publicId): Campaign
    {
        $campaign = Campaign::query()->with('brand')->where('public_id', $publicId)->firstOrFail();
        $this->authorizeBrand($campaign->brand);

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Campaign $campaign): array
    {
        return [
            'id' => $campaign->public_id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'objective' => $campaign->objective,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'content_count' => (int) ($campaign->content_items_count ?? 0),
        ];
    }
}
