<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Modules\SocialConnections\Contracts\RemoteDestination;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Exceptions\InvalidOAuthStateException;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialTokenEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialConnectionService
{
    private const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly SocialProviderManager $manager,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Inicia el flujo OAuth: genera state (single-use) + PKCE y devuelve la URL
     * de autorización del proveedor.
     */
    public function authorize(Brand $brand, string $providerKey, User $user): string
    {
        $record = $this->manager->record($providerKey);
        $adapter = $this->manager->adapter($providerKey);

        if ($record === null || ! $record->is_enabled || $adapter === null) {
            throw ValidationException::withMessages([
                'provider' => 'El proveedor social seleccionado no está disponible.',
            ]);
        }

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
            $adapter->defaultScopes(),
            $record->credentialMap(),
        );
    }

    /**
     * Completa el flujo OAuth desde el callback. Valida el state (single-use),
     * intercambia el código y persiste la conexión con sus destinos.
     */
    public function complete(string $providerKey, string $code, string $state): SocialConnection
    {
        $context = Cache::pull($this->stateKey($state));

        if ($context === null || ($context['provider'] ?? null) !== $providerKey) {
            throw new InvalidOAuthStateException('El parámetro de seguridad (state) no es válido o expiró.');
        }

        $adapter = $this->manager->adapter($providerKey);
        $record = $this->manager->record($providerKey);
        if ($adapter === null || $record === null) {
            throw new InvalidOAuthStateException('Proveedor no disponible.');
        }

        $credentials = $record->credentialMap();
        $tokens = $adapter->exchangeCode($code, $this->redirectUri($providerKey), $context['code_verifier'] ?? null, $credentials);
        $label = $adapter->accountLabel($tokens, $credentials);
        $destinations = $adapter->fetchDestinations($tokens, $credentials);

        return DB::transaction(function () use ($context, $providerKey, $tokens, $label, $destinations): SocialConnection {
            $connection = SocialConnection::query()->create([
                'organization_id' => $context['organization_id'],
                'brand_id' => $context['brand_id'],
                'provider' => $providerKey,
                'status' => ConnectionStatus::CONNECTED->value,
                'external_account_name' => $label,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_expires_at' => $tokens->expiresAt,
                'scopes' => $tokens->scopes,
                'connected_by_user_id' => $context['user_id'],
                'last_health_check_at' => now(),
            ]);

            foreach ($destinations as $destination) {
                /** @var RemoteDestination $destination */
                $connection->destinations()->create([
                    'organization_id' => $context['organization_id'],
                    'external_id' => $destination->externalId,
                    'name' => $destination->name,
                    'type' => $destination->type,
                    'capabilities' => $destination->capabilities,
                    'metadata' => $destination->metadata,
                ]);
            }

            SocialTokenEvent::query()->create([
                'organization_id' => $context['organization_id'],
                'social_connection_id' => $connection->id,
                'event' => 'connected',
            ]);

            $this->audit->log(
                AuditAction::SOCIAL_CONNECTED,
                $connection,
                ['provider' => $providerKey, 'account' => $label],
                organizationId: $context['organization_id'],
            );

            return $connection;
        });
    }

    /**
     * Crea una conexión manualmente a partir de un token capturado a mano (p. ej.
     * un System User token o uno de pruebas), sin pasar por el flujo OAuth. Útil
     * para conectar/probar antes de la revisión de la app. El token se cifra.
     *
     * @param  array{external_account_name: string, external_account_id?: string|null, access_token: string, refresh_token?: string|null, token_expires_at?: string|null, destinations?: list<array{external_id: string, name: string, type?: string}>}  $data
     */
    public function connectManually(Brand $brand, string $providerKey, User $user, array $data): SocialConnection
    {
        $record = $this->manager->record($providerKey);
        $adapter = $this->manager->adapter($providerKey);

        if ($record === null || ! $record->is_enabled || $adapter === null) {
            throw ValidationException::withMessages([
                'provider' => 'El proveedor social seleccionado no está disponible.',
            ]);
        }

        $capabilities = $adapter->capabilities();

        return DB::transaction(function () use ($brand, $providerKey, $user, $data, $capabilities): SocialConnection {
            $connection = SocialConnection::query()->create([
                'organization_id' => $brand->organization_id,
                'brand_id' => $brand->id,
                'provider' => $providerKey,
                'status' => ConnectionStatus::CONNECTED->value,
                'external_account_id' => $data['external_account_id'] ?? null,
                'external_account_name' => $data['external_account_name'],
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'token_expires_at' => $data['token_expires_at'] ?? null,
                'scopes' => [],
                'connected_by_user_id' => $user->id,
                'last_health_check_at' => now(),
                'meta' => ['manual' => true],
            ]);

            foreach ($data['destinations'] ?? [] as $destination) {
                $connection->destinations()->create([
                    'organization_id' => $brand->organization_id,
                    'external_id' => $destination['external_id'],
                    'name' => $destination['name'],
                    'type' => $destination['type'] ?? 'page',
                    'capabilities' => $capabilities,
                ]);
            }

            SocialTokenEvent::query()->create([
                'organization_id' => $brand->organization_id,
                'social_connection_id' => $connection->id,
                'event' => 'connected',
            ]);

            $this->audit->log(
                AuditAction::SOCIAL_CONNECTED,
                $connection,
                ['provider' => $providerKey, 'account' => $data['external_account_name'], 'manual' => true],
                organizationId: $brand->organization_id,
            );

            return $connection;
        });
    }

    public function disconnect(SocialConnection $connection): void
    {
        $connection->forceFill([
            'status' => ConnectionStatus::REVOKED->value,
            'access_token' => null,
            'refresh_token' => null,
        ])->save();

        SocialTokenEvent::query()->create([
            'organization_id' => $connection->organization_id,
            'social_connection_id' => $connection->id,
            'event' => 'revoked',
        ]);

        $this->audit->log(AuditAction::SOCIAL_DISCONNECTED, $connection, [
            'provider' => $connection->provider,
        ]);

        $connection->delete();
    }

    public function refresh(SocialConnection $connection): SocialConnection
    {
        $adapter = $this->manager->adapter($connection->provider);
        $record = $this->manager->record($connection->provider);

        if ($adapter === null || $record === null || $connection->refresh_token === null) {
            return $connection;
        }

        $tokens = $adapter->refreshTokens($connection->refresh_token, $record->credentialMap());

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
            'status' => ConnectionStatus::CONNECTED->value,
            'last_health_check_at' => now(),
        ])->save();

        SocialTokenEvent::query()->create([
            'organization_id' => $connection->organization_id,
            'social_connection_id' => $connection->id,
            'event' => 'refreshed',
        ]);

        $this->audit->log(AuditAction::SOCIAL_TOKEN_REFRESHED, $connection, [
            'provider' => $connection->provider,
        ]);

        return $connection;
    }

    private function stateKey(string $state): string
    {
        return 'social_oauth:' . $state;
    }

    private function redirectUri(string $providerKey): string
    {
        return url("/api/v1/social/callback/{$providerKey}");
    }
}
