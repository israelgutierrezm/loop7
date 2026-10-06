<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Services;

use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Contracts\CompetitorSource;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;
use App\Modules\Competitors\Sources\FacebookCompetitorSource;
use App\Modules\Competitors\Sources\FakeCompetitorSource;
use App\Modules\Competitors\Sources\InstagramCompetitorSource;
use App\Modules\Competitors\Sources\ThreadsCompetitorSource;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;

/**
 * Redes en las que se puede seguir a la competencia y con qué cuenta propia se
 * consulta cada una. Instagram, Facebook y Threads exigen una cuenta conectada
 * de esa red en la organización (de preferencia, de la misma marca).
 */
class CompetitorSources
{
    /** @var array<string, CompetitorSource> */
    private array $sources;

    public function __construct(
        private readonly SocialProviderManager $providers,
        private readonly SocialConnectionService $connections,
    ) {
        $this->sources = [];
        foreach ([new InstagramCompetitorSource(), new FacebookCompetitorSource(), new ThreadsCompetitorSource(), new FakeCompetitorSource()] as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    public function get(string $key): ?CompetitorSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * Redes activas en la plataforma, con si se pueden usar en la marca y por qué no.
     *
     * @return list<array{key: string, label: string, hint: string, posts: bool, available: bool, reason: string|null}>
     */
    public function forBrand(Brand $brand): array
    {
        $list = [];
        foreach ($this->sources as $key => $source) {
            if (! $this->providers->isEnabled($key)) {
                continue;
            }
            $reason = $this->unavailableReason($source, $brand);
            $list[] = [
                'key' => $key,
                'label' => $source->label(),
                'hint' => $source->handleHint(),
                'posts' => $source->providesPosts(),
                'available' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $list;
    }

    /**
     * Cuenta propia con la que consultar la red.
     *
     * @throws CompetitorSourceException con el motivo si no se puede
     */
    public function viewer(string $key, Brand $brand): CompetitorViewer
    {
        $source = $this->get($key);
        if ($source === null || ! $this->providers->isEnabled($key)) {
            throw new CompetitorSourceException('Esa red no está disponible para seguir a la competencia.');
        }
        if ($key === 'fake') {
            return new CompetitorViewer('fake', 'fake');
        }

        $reason = $this->unavailableReason($source, $brand);
        $connection = $reason === null ? $this->connection($key, $brand) : null;
        $destination = $connection?->destinations()->withoutGlobalScopes()->orderBy('id')->first();
        if ($connection === null || $destination === null) {
            throw new CompetitorSourceException($reason ?? "Conecta una cuenta de {$source->label()} para seguir a la competencia en esa red.");
        }

        try {
            $tokens = $this->connections->freshTokens($connection, $destination);
        } catch (SocialTokenExpiredException) {
            throw new CompetitorSourceException("La cuenta de {$source->label()} conectada perdió el acceso: reconéctala en Redes sociales.");
        }

        return new CompetitorViewer(
            token: (string) ($tokens->destinationToken ?: $tokens->accessToken),
            externalId: $destination->external_id,
            credentials: $this->providers->credentials($key),
        );
    }

    private function unavailableReason(CompetitorSource $source, Brand $brand): ?string
    {
        if ($source->key() === 'fake') {
            return null;
        }

        $connection = $this->connection($source->key(), $brand);
        if ($connection === null) {
            return "Conecta una cuenta de {$source->label()} en alguna marca para seguir a la competencia en esa red.";
        }
        if ($source->key() === 'threads' && ! in_array(ThreadsCompetitorSource::SCOPE, $connection->scopes ?? [], true)) {
            return 'Reconecta Threads con el permiso «threads_profile_discovery» (actívalo antes en SUPERADMIN → Redes sociales).';
        }

        return null;
    }

    /**
     * Conexión activa de la red en la organización; primero, la de la propia marca.
     */
    private function connection(string $provider, Brand $brand): ?SocialConnection
    {
        return SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $brand->organization_id)
            ->where('provider', $provider)
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->whereHas('destinations', fn ($q) => $q->withoutGlobalScopes())
            ->orderByRaw('CASE WHEN brand_id = ? THEN 0 ELSE 1 END', [$brand->id])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, CompetitorSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
