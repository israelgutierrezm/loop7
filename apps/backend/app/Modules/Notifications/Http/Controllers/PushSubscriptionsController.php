<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\StorePushSubscriptionRequest;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\Services\WebPushSender;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Navegadores del usuario con avisos push. Cada navegador se identifica por su
 * endpoint; si otra cuenta lo registra después, pasa a ser de ella.
 */
class PushSubscriptionsController extends Controller
{
    /** Navegadores por usuario: al pasar el límite se olvidan los menos usados. */
    private const MAX_DEVICES = 10;

    public function __construct(private readonly NotificationChannels $channels)
    {
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        if (! $this->channels->pushReady()) {
            throw ValidationException::withMessages(['endpoint' => 'Los avisos push no están disponibles en este momento.']);
        }

        $user = $request->user();
        $endpoint = (string) $request->validated('endpoint');

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => (string) $request->validated('keys.p256dh'),
                'auth_token' => (string) $request->validated('keys.auth'),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        );

        $keep = $user->pushSubscriptions()
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(self::MAX_DEVICES)
            ->pluck('id');
        $user->pushSubscriptions()->whereNotIn('id', $keep->all())->delete();

        return ApiResponse::success(
            ['devices' => $user->pushSubscriptions()->count()],
            'Avisos push activados en este navegador.',
            status: 201,
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $user = $request->user();

        // Sólo los del propio usuario.
        $user->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->delete();

        return ApiResponse::success(['devices' => $user->pushSubscriptions()->count()], 'Avisos push desactivados en este navegador.');
    }

    /**
     * Aviso de prueba a todos los navegadores del usuario.
     */
    public function test(Request $request, WebPushSender $sender): JsonResponse
    {
        try {
            $delivered = $sender->send($request->user(), [
                'title' => 'Avisos push activados',
                'body' => 'Así verás los avisos de ' . config('app.name') . ' en este dispositivo.',
                'path' => '/app/profile#notificaciones',
                'tag' => 'push.test',
            ]);
        } catch (Throwable $e) {
            Log::warning('Web Push: falló el aviso de prueba.', ['user_id' => $request->user()->id, 'error' => $e::class]);
            $delivered = 0;
        }

        if ($delivered === 0) {
            throw ValidationException::withMessages(['endpoint' => 'No se pudo entregar el aviso a ningún navegador. Vuelve a activarlos.']);
        }

        return ApiResponse::success(['delivered' => $delivered], $delivered === 1 ? 'Aviso de prueba enviado.' : "Aviso de prueba enviado a {$delivered} navegadores.");
    }
}
