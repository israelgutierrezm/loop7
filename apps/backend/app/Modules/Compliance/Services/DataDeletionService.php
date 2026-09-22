<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Jobs\PurgeExternalUserData;
use App\Modules\Compliance\Models\DataDeletionRequest;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gestiona el borrado de datos exigido por Meta (App Review): verifica el
 * signed_request firmado con el App Secret, registra la solicitud y lanza la
 * purga de los datos vinculados al usuario externo (docs/19).
 */
class DataDeletionService
{
    /**
     * Procesa un signed_request de Meta y devuelve la solicitud registrada.
     */
    public function handleSignedRequest(string $provider, string $signedRequest): DataDeletionRequest
    {
        $secret = $this->appSecret($provider);
        $payload = $this->parseSignedRequest($signedRequest, $secret);

        $userId = (string) ($payload['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('El signed_request no contiene user_id.');
        }

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
