<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks de TikTok (docs/06). Firma: cabecera `TikTok-Signature: t=…,s=…`
 * con HMAC-SHA256 hexadecimal de «t.cuerpo» y el client secret de la app.
 * TikTok entrega al menos una vez y reintenta hasta 72 h: se procesa cada
 * aviso una sola vez y se responde 200 al momento.
 *
 * - `authorization.removed`: la persona quitó el acceso desde TikTok → las
 *   conexiones de esa cuenta pasan a «Expiradas» (aviso para reconectar).
 * - `post.publish.publicly_available`: el video ya es público → la publicación
 *   guarda su id y enlace definitivos (y empiezan sus métricas).
 */
class TikTokWebhookController extends Controller
{
    /** TikTok reintenta durante 72 h con la misma firma. */
    private const MAX_AGE_SECONDS = 3 * 24 * 3600 + 3600;

    public function __invoke(Request $request, SocialProviderManager $manager, SocialConnectionService $connections): JsonResponse
    {
        $credentials = $manager->credentials('tiktok');
        $secret = $credentials['client_secret'] ?? '';
        if ($secret === '') {
            return response()->json(['error' => 'TikTok no está configurado.'], 404);
        }

        $body = (string) $request->getContent();
        if (! $this->validSignature((string) $request->header('TikTok-Signature', ''), $body, $secret)) {
            return response()->json(['error' => 'Firma no válida.'], 401);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ($payload['client_key'] ?? null) !== ($credentials['client_id'] ?? '')) {
            return response()->json(['error' => 'Aviso no reconocido.'], 400);
        }

        // Entrega «al menos una vez»: cada aviso se procesa una sola vez.
        if (! Cache::add('tiktok-webhook:' . sha1($body), true, now()->addSeconds(self::MAX_AGE_SECONDS))) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $content = is_string($payload['content'] ?? null) ? (array) json_decode($payload['content'], true) : [];
        $openId = (string) ($payload['user_openid'] ?? '');

        match ((string) ($payload['event'] ?? '')) {
            'authorization.removed' => $this->authorizationRemoved($openId, $connections),
            'post.publish.publicly_available' => $this->publiclyAvailable((string) ($content['publish_id'] ?? ''), (string) ($content['post_id'] ?? '')),
            default => null, // publicación completada/fallida: el job ya lo consulta
        };

        return response()->json(['received' => true]);
    }

    private function validSignature(string $header, string $body, string $secret): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['s'] ?? '');
        if ($timestamp === 0 || $signature === '' || abs(time() - $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature);
    }

    private function authorizationRemoved(string $openId, SocialConnectionService $connections): void
    {
        if ($openId === '') {
            return;
        }

        $affected = SocialConnection::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('provider', 'tiktok')
            ->where('external_account_id', $openId)
            ->get();

        foreach ($affected as $connection) {
            $connection->forceFill(['access_token' => null, 'refresh_token' => null])->save();
            $connections->markExpired($connection, 'La cuenta quitó el acceso a la app desde TikTok.');
        }
    }

    private function publiclyAvailable(string $publishId, string $postId): void
    {
        if ($publishId === '' || preg_match('/^\d+$/', $postId) !== 1) {
            return;
        }

        $targets = PublicationTarget::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('remote_id', $publishId)
            ->whereHas('destination.connection', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->where('provider', 'tiktok'))
            ->get();

        foreach ($targets as $target) {
            $profile = rtrim((string) $target->remote_url, '/');
            $target->forceFill([
                'remote_id' => $postId,
                'remote_url' => str_contains($profile, '/video/') ? $profile : $profile . '/video/' . $postId,
            ])->save();
        }

        if ($targets->isEmpty()) {
            Log::info('TikTok: video público sin publicación asociada.', ['publish_id' => $publishId]);
        }
    }
}
