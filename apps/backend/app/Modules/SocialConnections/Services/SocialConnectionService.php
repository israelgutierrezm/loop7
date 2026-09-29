<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Contracts\OAuthTokens;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Contracts\RevokesAccess;
use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Events\SocialConnectionExpired;
use App\Modules\SocialConnections\Exceptions\InvalidOAuthStateException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Models\SocialTokenEvent;
use App\Support\Security\SecretRedactor;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialConnectionService
{
    private const STATE_TTL_MINUTES = 10;

    /** Se renueva antes de usarlo si caduca en menos de estos minutos. */
    private const REFRESH_MARGIN_MINUTES = 10;

    /** El sondeo periódico no renueva lo que se comprobó hace menos de esto. */
    private const REFRESH_CHECK_HOURS = 12;

    public function __construct(
        private readonly SocialProviderManager $manager,
        private readonly AuditLogger $audit,
        private readonly UsageService $usage,
    ) {
    }

    /**
     * Inicia el flujo OAuth: genera state (single-use) + PKCE y devuelve la URL
     * de autorización del proveedor.
     */
    public function authorize(Brand $brand, string $providerKey, User $user): string
    {
        $adapter = $this->assertAvailable($providerKey);

        $state = Str::random(40);
        $codeVerifier = $adapter->usesPkce() ? Str::random(96) : null;
        $codeChallenge = $codeVerifier !== null
            ? rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=')
            : null;

        Cache::put($this->stateKey($state), [
            'brand_id' => $brand->id,
            'organization_id' => $brand->organization_id,
            'provider' => $providerKey,
            'user_id' => $user->id,
            'code_verifier' => $codeVerifier,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $adapter->authorizeUrl(
            $this->redirectUri($providerKey),
            $state,
            $codeChallenge,
            $this->manager->scopes($providerKey),
            $this->manager->credentials($providerKey),
        );
    }

    /**
     * Completa el flujo OAuth desde el callback. Valida el state (single-use),
     * intercambia el código y persiste la conexión con sus destinos. Si la cuenta
     * ya estaba conectada a la marca, la actualiza (reconexión) en vez de duplicarla.
     */
    public function complete(string $providerKey, string $code, string $state): SocialConnection
    {
        $context = Cache::pull($this->stateKey($state));

        if (! is_array($context) || ($context['provider'] ?? null) !== $providerKey) {
            throw new InvalidOAuthStateException('El parámetro de seguridad (state) no es válido o expiró.');
        }

        $adapter = $this->manager->adapter($providerKey);
        if ($adapter === null || $this->manager->record($providerKey) === null) {
            throw new InvalidOAuthStateException('Proveedor no disponible.');
        }

        $credentials = $this->manager->credentials($providerKey);
        $tokens = $adapter->exchangeCode($code, $this->redirectUri($providerKey), $context['code_verifier'] ?? null, $credentials);
        $account = $adapter->fetchAccount($tokens, $credentials);
        $destinations = $adapter->fetchDestinations($tokens, $credentials);

        return DB::transaction(fn (): SocialConnection => $this->persist(
            organizationId: (int) $context['organization_id'],
            brandId: (int) $context['brand_id'],
            providerKey: $providerKey,
            userId: (int) $context['user_id'],
            accountId: $account->id,
            accountName: $account->name,
            tokens: $tokens,
            destinations: $destinations,
            meta: [],
        ));
    }

    /**
     * Crea una conexión a partir de un token capturado a mano (p. ej. un token de
     * usuario de sistema o de pruebas), sin pasar por OAuth. El token se cifra.
     * Si el proveedor puede listar los destinos con ese token, se completan sus
     * tokens propios (page tokens) automáticamente.
     *
     * @param  array{external_account_name: string, external_account_id?: string|null, access_token: string, refresh_token?: string|null, token_expires_at?: string|null, destinations?: list<array{external_id: string, name: string, type?: string|null}>}  $data
     */
    public function connectManually(Brand $brand, string $providerKey, User $user, array $data): SocialConnection
    {
        $adapter = $this->assertAvailable($providerKey);

        $tokens = new OAuthTokens(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: isset($data['token_expires_at']) ? Carbon::parse($data['token_expires_at']) : null,
        );

        try {
            $remote = $adapter->fetchDestinations($tokens, $this->manager->credentials($providerKey));
        } catch (Throwable) {
            $remote = []; // el token puede no permitir listar destinos: se usan los capturados
        }
        $remoteById = collect($remote)->keyBy(fn (RemoteDestination $d) => $d->externalId);

        $given = $data['destinations'] ?? [];
        $destinations = $given === []
            ? $remote
            : array_map(fn (array $d) => new RemoteDestination(
                externalId: $d['external_id'],
                name: $d['name'],
                type: $d['type'] ?? $remoteById->get($d['external_id'])->type ?? 'page',
                capabilities: $adapter->capabilities(),
                metadata: $remoteById->get($d['external_id'])->metadata ?? [],
                accessToken: $remoteById->get($d['external_id'])?->accessToken,
            ), $given);

        return DB::transaction(fn (): SocialConnection => $this->persist(
            organizationId: $brand->organization_id,
            brandId: $brand->id,
            providerKey: $providerKey,
            userId: $user->id,
            accountId: $data['external_account_id'] ?? null,
            accountName: $data['external_account_name'],
            tokens: $tokens,
            destinations: $destinations,
            meta: ['manual' => true],
        ));
    }

    public function disconnect(SocialConnection $connection): void
    {
        $revoked = $this->revokeRemotely($connection);

        $connection->forceFill([
            'status' => ConnectionStatus::REVOKED->value,
            'access_token' => null,
            'refresh_token' => null,
        ])->save();
        $connection->destinations()->update(['access_token' => null, 'is_active' => false]);

        $this->recordEvent($connection, 'revoked');
        $this->audit->log(AuditAction::SOCIAL_DISCONNECTED, $connection, [
            'provider' => $connection->provider,
            'revoked_remotely' => $revoked,
        ]);

        $connection->delete();
    }

    /**
     * Revoca el acceso en la red si el proveedor lo permite (YouTube lo exige).
     * No se revoca si otra conexión (de otra marca u organización) usa la misma
     * cuenta: la red invalidaría también sus tokens. Un fallo no impide
     * desconectar.
     */
    private function revokeRemotely(SocialConnection $connection): bool
    {
        $adapter = $this->manager->adapter($connection->provider);
        if (! $adapter instanceof RevokesAccess || ($connection->access_token === null && $connection->refresh_token === null)) {
            return false;
        }

        $shared = $connection->external_account_id !== null && SocialConnection::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKeyNot($connection->id)
            ->where('provider', $connection->provider)
            ->where('external_account_id', $connection->external_account_id)
            ->where('status', '!=', ConnectionStatus::REVOKED->value)
            ->exists();
        if ($shared) {
            return false;
        }

        try {
            $adapter->revokeAccess($connection->toTokens(), $this->manager->credentials($connection->provider));

            return true;
        } catch (Throwable $e) {
            Log::warning('No se pudo revocar el acceso en la red al desconectar.', [
                'provider' => $connection->provider,
                'connection' => $connection->id,
                'error' => SecretRedactor::redact($e->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * Tokens listos para usar: si el de acceso caduca en unos minutos y hay
     * refresh token, se renueva antes (redes con tokens de 1–24 h: X, YouTube,
     * TikTok). Publicar, métricas e inbox pasan siempre por aquí.
     */
    public function freshTokens(SocialConnection $connection, ?SocialConnectionDestination $destination = null): OAuthTokens
    {
        if ($connection->refresh_token !== null
            && $connection->token_expires_at !== null
            && $connection->token_expires_at->lte(now()->addMinutes(self::REFRESH_MARGIN_MINUTES))
        ) {
            $this->refresh($connection);
            if ($connection->status === ConnectionStatus::EXPIRED) {
                throw new SocialTokenExpiredException('No se pudo renovar el acceso a la red social.');
            }
        }

        return $connection->toTokens($destination);
    }

    /**
     * Renueva los tokens con el refresh token del proveedor. Si falla, la
     * conexión queda marcada como expirada para que el usuario la reconecte.
     *
     * Con bloqueo por conexión: algunos proveedores (X) rotan el refresh token
     * y dos renovaciones simultáneas con el mismo invalidarían la conexión.
     */
    public function refresh(SocialConnection $connection): SocialConnection
    {
        try {
            return Cache::lock('social-token-refresh:' . $connection->id, 60)
                ->block(30, fn (): SocialConnection => $this->refreshLocked($connection));
        } catch (LockTimeoutException) {
            // Otro proceso lo está renovando: se usan los tokens que haya.
            return $connection->refresh();
        }
    }

    private function refreshLocked(SocialConnection $connection): SocialConnection
    {
        // Relee: quien tenía el bloqueo pudo renovarlo (y rotar el refresh token).
        $connection->refresh();
        $adapter = $this->manager->adapter($connection->provider);

        if ($adapter === null || $connection->refresh_token === null) {
            return $connection;
        }

        try {
            $tokens = $adapter->refreshTokens($connection->refresh_token, $this->manager->credentials($connection->provider));
        } catch (Throwable $e) {
            $this->markExpired($connection, $e->getMessage());

            return $connection;
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
            'status' => ConnectionStatus::CONNECTED->value,
            'last_health_check_at' => now(),
        ])->save();

        $this->recordEvent($connection, 'refreshed');
        $this->audit->log(
            AuditAction::SOCIAL_TOKEN_REFRESHED,
            $connection,
            ['provider' => $connection->provider],
            organizationId: $connection->organization_id,
        );

        return $connection;
    }

    /**
     * Renueva los tokens que caducan en las próximas 24 h y marca como expiradas
     * las conexiones cuyo token ya caducó sin posibilidad de renovarse.
     *
     * @return array{refreshed: int, expired: int}
     */
    public function refreshDue(): array
    {
        $refreshed = 0;
        $expired = 0;

        SocialConnection::query()->withoutGlobalScopes()
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDay())
            ->each(function (SocialConnection $connection) use (&$refreshed, &$expired): void {
                if ($connection->refresh_token !== null) {
                    // Tokens cortos (1–24 h): se renuevan al usarlos; aquí basta
                    // comprobar cada pocas horas que el refresh token sigue siendo válido.
                    if ($connection->last_health_check_at?->gt(now()->subHours(self::REFRESH_CHECK_HOURS))) {
                        return;
                    }
                    $this->refresh($connection);
                    $connection->status === ConnectionStatus::CONNECTED ? $refreshed++ : $expired++;
                } elseif ($connection->isExpired()) {
                    $this->markExpired($connection, 'El token de acceso caducó.');
                    $expired++;
                }
            });

        return ['refreshed' => $refreshed, 'expired' => $expired];
    }

    /**
     * El proveedor rechazó el token: la conexión queda "Expirada" (necesita
     * atención) hasta que el usuario la reconecte.
     */
    public function markExpired(SocialConnection $connection, string $reason): void
    {
        if ($connection->status === ConnectionStatus::EXPIRED) {
            return;
        }

        $connection->forceFill([
            'status' => ConnectionStatus::EXPIRED->value,
            'last_health_check_at' => now(),
        ])->save();

        $this->recordEvent($connection, 'expired');
        $this->audit->log(
            AuditAction::SOCIAL_TOKEN_EXPIRED,
            $connection,
            ['provider' => $connection->provider, 'reason' => Str::limit($reason, 300)],
            organizationId: $connection->organization_id,
        );

        SocialConnectionExpired::dispatch($connection);
    }

    public function redirectUri(string $providerKey): string
    {
        return url("/api/v1/social/callback/{$providerKey}");
    }

    /**
     * @param  list<RemoteDestination>  $destinations
     * @param  array<string, mixed>  $meta
     */
    private function persist(
        int $organizationId,
        int $brandId,
        string $providerKey,
        int $userId,
        ?string $accountId,
        string $accountName,
        OAuthTokens $tokens,
        array $destinations,
        array $meta,
    ): SocialConnection {
        // Incluye las desconectadas (soft delete): reconectar la misma cuenta la
        // restaura en lugar de duplicarla.
        $existing = $accountId === null || $accountId === '' ? null : SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('provider', $providerKey)
            ->where('external_account_id', $accountId)
            ->first();

        if ($existing === null || $existing->trashed()) {
            // Límite de plan: cuentas sociales conectadas (reconectar una activa no cuenta).
            $organization = Organization::query()->findOrFail($organizationId);
            $this->usage->ensureWithin(
                $organization,
                Entitlement::SOCIAL_ACCOUNTS_MAX,
                $this->usage->socialAccounts($organization),
                1,
                'Has alcanzado el número de cuentas sociales incluidas en tu plan.',
            );
        }

        $attributes = [
            'status' => ConnectionStatus::CONNECTED->value,
            'external_account_name' => $accountName,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt,
            'scopes' => $tokens->scopes,
            'connected_by_user_id' => $userId,
            'last_health_check_at' => now(),
            'meta' => $meta === [] ? null : $meta,
        ];

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill($attributes)->save();
            $connection = $existing;
        } else {
            $connection = new SocialConnection();
            $connection->forceFill([
                ...$attributes,
                'organization_id' => $organizationId,
                'brand_id' => $brandId,
                'provider' => $providerKey,
                'external_account_id' => $accountId,
            ])->save();
        }

        $this->syncDestinations($connection, $destinations);
        $this->recordEvent($connection, $existing !== null ? 'reconnected' : 'connected');

        $this->audit->log(
            $existing !== null ? AuditAction::SOCIAL_RECONNECTED : AuditAction::SOCIAL_CONNECTED,
            $connection,
            ['provider' => $providerKey, 'account' => $accountName, 'manual' => (bool) ($meta['manual'] ?? false)],
            organizationId: $organizationId,
        );

        return $connection;
    }

    /**
     * Crea/actualiza los destinos por su id externo y desactiva los que el
     * proveedor ya no devuelve (se conservan por el historial de publicaciones).
     *
     * @param  list<RemoteDestination>  $destinations
     */
    private function syncDestinations(SocialConnection $connection, array $destinations): void
    {
        $seen = [];
        foreach ($destinations as $destination) {
            $model = SocialConnectionDestination::query()->withoutGlobalScopes()->firstOrNew([
                'social_connection_id' => $connection->id,
                'external_id' => $destination->externalId,
            ]);
            $model->forceFill([
                'organization_id' => $connection->organization_id,
                'name' => $destination->name,
                'type' => $destination->type,
                'capabilities' => $destination->capabilities,
                'metadata' => $destination->metadata === [] ? null : $destination->metadata,
                'is_active' => true,
            ]);
            if ($destination->accessToken !== null) {
                $model->access_token = $destination->accessToken;
            }
            $model->save();
            $seen[] = $destination->externalId;
        }

        SocialConnectionDestination::query()->withoutGlobalScopes()
            ->where('social_connection_id', $connection->id)
            ->whereNotIn('external_id', $seen)
            ->update(['is_active' => false]);
    }

    private function assertAvailable(string $providerKey): SocialProviderInterface
    {
        $record = $this->manager->record($providerKey);
        $adapter = $this->manager->adapter($providerKey);

        if ($record === null || ! $record->is_enabled || $adapter === null) {
            throw ValidationException::withMessages([
                'provider' => 'El proveedor social seleccionado no está disponible.',
            ]);
        }

        return $adapter;
    }

    private function recordEvent(SocialConnection $connection, string $event): void
    {
        SocialTokenEvent::query()->withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'social_connection_id' => $connection->id,
            'event' => $event,
        ]);
    }

    private function stateKey(string $state): string
    {
        return 'social_oauth:' . $state;
    }
}
