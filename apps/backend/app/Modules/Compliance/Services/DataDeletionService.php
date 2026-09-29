<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Jobs\PurgeExternalUserData;
use App\Modules\Compliance\Models\DataDeletionRequest;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Callbacks de las apps de Meta (docs/06): borrado de datos y desautorización.
 * Llegan como `signed_request` firmado con el secreto de la app que los envía:
 * la de Facebook (que también usa Instagram) o la app propia de Threads.
 */
class DataDeletionService
{
    /**
     * App de Meta que envía el callback → redes cuyas conexiones cubre.
     *
     * @var array<string, list<string>>
     */
    private const APPS = [
        'facebook' => ['facebook', 'instagram'],
        'threads' => ['threads'],
    ];

    public function __construct(private readonly SocialConnectionService $connections)
    {
    }

    /**
     * @return list<string>
     */
    public static function apps(): array
    {
        return array_keys(self::APPS);
    }

    /**
     * Redes cuyas conexiones pertenecen a la app que envía el callback.
     *
     * @return list<string>
     */
    public static function providersOf(string $app): array
    {
        return self::APPS[$app] ?? [$app];
    }

    /**
     * Procesa un signed_request de borrado de datos y devuelve la solicitud registrada.
     */
    public function handleSignedRequest(string $provider, string $signedRequest): DataDeletionRequest
    {
        $userId = $this->userId($provider, $signedRequest);

        $request = DataDeletionRequest::query()->create([
            'confirmation_code' => strtoupper(Str::random(16)),
            'provider' => $provider,
            'external_user_id' => $userId,
            'status' => 'pending',
        ]);

        PurgeExternalUserData::dispatch($request->id);

        return $request;
    }

    /**
     * La persona quitó la app desde la red: sus conexiones dejan de poder
     * publicar y quedan «Expiradas» (con aviso para reconectar). Devuelve cuántas.
     */
    public function deauthorize(string $provider, string $signedRequest): int
    {
        $userId = $this->userId($provider, $signedRequest);

        $affected = SocialConnection::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('provider', self::providersOf($provider))
            ->where('external_account_id', $userId)
            ->get();

        foreach ($affected as $connection) {
            $connection->forceFill(['access_token' => null, 'refresh_token' => null])->save();
            $connection->destinations()->update(['access_token' => null]);
            $this->connections->markExpired($connection, 'La cuenta quitó el acceso a la app desde la red social.');
        }

        return $affected->count();
    }

    /**
     * Verifica la firma HMAC-SHA256 y devuelve el payload decodificado.
     *
     * @return array<string, mixed>
     */
    public function parseSignedRequest(string $signedRequest, string $secret): array
    {
        if (! str_contains($signedRequest, '.')) {
            throw new RuntimeException('signed_request con formato inválido.');
        }

        [$encodedSig, $encodedPayload] = explode('.', $signedRequest, 2);
        $expected = hash_hmac('sha256', $encodedPayload, $secret, true);
        $provided = $this->base64UrlDecode($encodedSig);

        if (! hash_equals($expected, $provided)) {
            throw new RuntimeException('Firma de signed_request inválida.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (! is_array($data)) {
            throw new RuntimeException('Payload de signed_request inválido.');
        }

        return $data;
    }

    private function userId(string $provider, string $signedRequest): string
    {
        if (! array_key_exists($provider, self::APPS)) {
            throw new RuntimeException('Proveedor no admitido.');
        }

        $payload = $this->parseSignedRequest($signedRequest, $this->appSecret($provider));
        $userId = (string) ($payload['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('El signed_request no contiene user_id.');
        }

        return $userId;
    }

    private function appSecret(string $provider): string
    {
        $record = SocialProvider::query()->where('key', $provider)->first();
        $secret = $record?->credentialMap()['client_secret'] ?? '';

        if ($secret === '') {
            throw new RuntimeException("El proveedor {$provider} no tiene client_secret configurado.");
        }

        return $secret;
    }

    private function base64UrlDecode(string $input): string
    {
        return (string) base64_decode(strtr($input, '-_', '+/'), true);
    }
}
