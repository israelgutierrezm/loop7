<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\ImageGenerationRequest;
use App\Modules\Ai\Contracts\TextGenerationRequest;
use App\Modules\Ai\Enums\AiModality;
use App\Modules\Ai\Enums\AiOperation;
use App\Modules\Ai\Exceptions\AiProviderNotConfiguredException;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Models\AiUsageLog;
use App\Modules\Ai\Models\OrganizationAiKey;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Throwable;

/**
 * Orquesta la generación de IA: resuelve proveedor (plataforma o BYOK), aplica
 * el Brand Brain como contexto, controla y consume créditos, ejecuta el
 * adaptador y registra el uso. Todo acotado a la Organization/Brand.
 */
class AiGenerationService
{
    public function __construct(
        private readonly AiProviderManager $manager,
        private readonly AiCreditService $credits,
        private readonly BrandContextBuilder $context,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{text: string, model: string, credits: int, remaining: int, byok: bool, provider: string, operation: string}
     */
    public function generateText(
        Brand $brand,
        User $user,
        string $prompt,
        AiOperation $operation,
        ?string $network = null,
    ): array {
        $organization = $this->organizationFor($brand);
        $provider = $this->manager->providerFor(AiModality::TEXT);
        if ($provider === null) {
            throw new AiProviderNotConfiguredException();
        }

        $adapter = $this->manager->textAdapter($provider->key);
        if ($adapter === null) {
            throw new AiProviderNotConfiguredException();
        }

        [$credentials, $byok] = $this->resolveCredentials($organization, $provider);
        $creditCost = $byok ? 0 : $provider->creditCostFor($operation->value, $operation->defaultCredits());
        $this->credits->ensureCanConsume($organization, $creditCost);

        // Con la petición, el contexto incluye los fragmentos relevantes de los documentos (RAG).
        $systemContext = $this->context->build($brand, $prompt);
        $instruction = $network !== null ? $this->context->networkInstruction($network) : '';
        $fullPrompt = $instruction !== '' ? $instruction . "\n\n" . $prompt : $prompt;

        $request = new TextGenerationRequest(
            prompt: $fullPrompt,
            systemContext: $systemContext,
            model: $provider->defaultTextModel(),
        );

        $start = microtime(true);
        try {
            $result = $adapter->generateText($request, $credentials);
        } catch (Throwable $e) {
            $this->logFailure($organization, $brand, $user, $provider, $operation, AiModality::TEXT, $byok, $start);

            throw $e;
        }

        $this->credits->consume($organization, $creditCost);
        $this->recordUsage(
            $organization,
            $brand,
            $user,
            $provider,
            $operation,
            AiModality::TEXT,
            units: $result->totalTokens(),
            credits: $creditCost,
            byok: $byok,
            start: $start,
            model: $result->model,
        );

        $this->audit->log(AuditAction::AI_TEXT_GENERATED, $brand, [
            'operation' => $operation->value,
            'provider' => $provider->key,
            'credits' => $creditCost,
            'network' => $network,
        ], organizationId: $organization->id);

        return [
            'text' => $result->text,
            'model' => $result->model,
            'credits' => $creditCost,
            'remaining' => $this->credits->remaining($organization),
            'byok' => $byok,
            'provider' => $provider->key,
            'operation' => $operation->value,
        ];
    }

    /**
     * @return array{images: list<array{url: string, b64?: string}>, model: string, credits: int, remaining: int, byok: bool, provider: string}
     */
    public function generateImage(Brand $brand, User $user, string $prompt, string $size = '1024x1024'): array
    {
        $organization = $this->organizationFor($brand);
        $operation = AiOperation::GENERATE_IMAGE;
        $provider = $this->manager->providerFor(AiModality::IMAGE);
        if ($provider === null) {
            throw new AiProviderNotConfiguredException();
        }

        $adapter = $this->manager->imageAdapter($provider->key);
        if ($adapter === null) {
            throw new AiProviderNotConfiguredException();
        }

        [$credentials, $byok] = $this->resolveCredentials($organization, $provider);
        $creditCost = $byok ? 0 : $provider->creditCostFor($operation->value, $operation->defaultCredits());
        $this->credits->ensureCanConsume($organization, $creditCost);

        $request = new ImageGenerationRequest(
            prompt: $prompt,
            size: $size,
            model: $provider->defaultImageModel(),
        );

        $start = microtime(true);
        try {
            $result = $adapter->generateImage($request, $credentials);
        } catch (Throwable $e) {
            $this->logFailure($organization, $brand, $user, $provider, $operation, AiModality::IMAGE, $byok, $start);

            throw $e;
        }

        $this->credits->consume($organization, $creditCost);
        $this->recordUsage(
            $organization,
            $brand,
            $user,
            $provider,
            $operation,
            AiModality::IMAGE,
            units: count($result->images),
            credits: $creditCost,
            byok: $byok,
            start: $start,
            model: $result->model,
        );

        $this->audit->log(AuditAction::AI_IMAGE_GENERATED, $brand, [
            'provider' => $provider->key,
            'credits' => $creditCost,
        ], organizationId: $organization->id);

        return [
            'images' => $result->images,
            'model' => $result->model,
            'credits' => $creditCost,
            'remaining' => $this->credits->remaining($organization),
            'byok' => $byok,
            'provider' => $provider->key,
        ];
    }

    /**
     * Devuelve [credenciales, esBYOK]. Usa la clave propia de la Organization si
     * el plan lo permite (feature.byok) y existe una activa; si no, la de plataforma.
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    private function resolveCredentials(Organization $organization, AiProvider $provider): array
    {
        if ($this->entitlements->allows($organization, Entitlement::FEATURE_BYOK)) {
            $key = OrganizationAiKey::query()->withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('provider', $provider->key)
                ->where('is_active', true)
                ->first();

            if ($key !== null && $key->credentialMap() !== []) {
                return [$key->credentialMap(), true];
            }
        }

        return [$provider->credentialMap(), false];
    }

    private function organizationFor(Brand $brand): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($brand->organization_id);

        return $organization;
    }

    private function recordUsage(
        Organization $organization,
        Brand $brand,
        User $user,
        AiProvider $provider,
        AiOperation $operation,
        AiModality $modality,
        int $units,
        int $credits,
        bool $byok,
        float $start,
        string $model,
    ): void {
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'provider' => $provider->key,
            'model' => $model,
            'modality' => $modality->value,
            'operation' => $operation->value,
            'units' => $units,
            'credits' => $credits,
            'cost_cents' => 0,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => 'succeeded',
            'byok' => $byok,
        ]);
    }

    private function logFailure(
        Organization $organization,
        Brand $brand,
        User $user,
        AiProvider $provider,
        AiOperation $operation,
        AiModality $modality,
        bool $byok,
        float $start,
    ): void {
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'provider' => $provider->key,
            'model' => null,
            'modality' => $modality->value,
            'operation' => $operation->value,
            'units' => 0,
            'credits' => 0,
            'cost_cents' => 0,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => 'failed',
            'byok' => $byok,
        ]);

        $this->audit->log(AuditAction::AI_GENERATION_FAILED, $brand, [
            'operation' => $operation->value,
            'provider' => $provider->key,
        ], organizationId: $organization->id);
    }
}
