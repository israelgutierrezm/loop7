<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\EmbeddingProviderInterface;
use App\Modules\Ai\Enums\AiModality;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Models\AiUsageLog;
use App\Modules\Ai\Models\OrganizationAiKey;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;

/**
 * Resuelve con qué proveedor de embeddings trabaja una organización (docs/07):
 * 1) su clave propia (BYOK) si el plan lo permite; 2) un proveedor real de
 * plataforma habilitado y configurado; 3) el de prueba (desarrollo). Si no hay
 * ninguno, la búsqueda del Brand Brain funciona por palabras clave.
 */
final class EmbeddingService
{
    public const DIMENSIONS = 512;

    /** Entradas por petición al proveedor. */
    public const BATCH = 64;

    public function __construct(
        private readonly AiProviderManager $manager,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    public function spaceFor(Organization $organization): ?EmbeddingSpace
    {
        if ($this->entitlements->allows($organization, Entitlement::FEATURE_BYOK)) {
            $keys = OrganizationAiKey::query()->withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->get();
            foreach ($keys as $key) {
                $adapter = $this->manager->embeddingAdapter($key->provider);
                if ($adapter !== null && $key->credentialMap() !== []) {
                    return $this->space($key->provider, $adapter, $key->credentialMap(), byok: true);
                }
            }
        }

        $providers = AiProvider::query()->where('is_enabled', true)->orderByDesc('is_default')->get()
            ->filter(fn (AiProvider $p) => $this->manager->embeddingAdapter($p->key) !== null);

        // Un proveedor real configurado antes que el de prueba.
        $real = $providers->first(fn (AiProvider $p) => $p->key !== 'fake' && $p->isConfigured());
        if ($real !== null) {
            return $this->space($real->key, $this->manager->embeddingAdapter($real->key), $real->credentialMap(), byok: false, configured: $real);
        }

        $fake = $providers->first(fn (AiProvider $p) => $p->key === 'fake');

        return $fake !== null ? $this->space('fake', $this->manager->embeddingAdapter('fake'), [], byok: false, configured: $fake) : null;
    }

    /**
     * Vectores de varios textos, por lotes. Registra el uso (sin coste en créditos).
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(EmbeddingSpace $space, array $texts, Organization $organization, ?int $brandId = null, ?int $userId = null): array
    {
        $vectors = [];
        $tokens = 0;
        $start = microtime(true);
        foreach (array_chunk($texts, self::BATCH) as $batch) {
            $result = $space->embed($batch);
            array_push($vectors, ...$result['vectors']);
            $tokens += $result['tokens'];
        }

        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'brand_id' => $brandId,
            'user_id' => $userId,
            'provider' => $space->provider,
            'model' => $space->model,
            'modality' => AiModality::EMBEDDING->value,
            'operation' => 'index_document',
            'units' => $tokens,
            'credits' => 0,
            'cost_cents' => 0,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => 'succeeded',
            'byok' => $space->byok,
        ]);

        return $vectors;
    }

    /**
     * Vector de una consulta (búsqueda); no se registra como uso aparte.
     *
     * @return list<float>
     */
    public function embedQuery(EmbeddingSpace $space, string $query): array
    {
        return $space->embed([$query])['vectors'][0] ?? [];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function space(
        string $provider,
        ?EmbeddingProviderInterface $adapter,
        array $credentials,
        bool $byok,
        ?AiProvider $configured = null,
    ): ?EmbeddingSpace {
        if ($adapter === null) {
            return null;
        }
        $model = (string) ($configured?->config['embedding_model'] ?? '');

        return new EmbeddingSpace(
            provider: $provider,
            model: $model !== '' ? $model : $adapter->defaultEmbeddingModel(),
            dimensions: self::DIMENSIONS,
            byok: $byok,
            adapter: $adapter,
            credentials: $credentials,
        );
    }
}
