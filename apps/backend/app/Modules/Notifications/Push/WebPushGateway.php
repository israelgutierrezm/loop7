<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Models\PushSubscription;

/**
 * Envío Web Push (RFC 8030/8291, firma VAPID RFC 8292). Contrato propio para no
 * acoplar el módulo a una librería concreta y poder sustituirla en pruebas.
 */
interface WebPushGateway
{
    /**
     * Envía la carga a cada navegador. Devuelve cuántos la aceptaron y los
     * endpoints que ya no existen (el servicio push respondió 404 o 410) para
     * borrarlos.
     *
     * @param  list<PushSubscription>  $subscriptions
     * @param  array{public_key: string, private_key: string, subject: string}  $vapid
     * @return array{delivered: int, expired: list<string>}
     */
    public function send(array $subscriptions, string $payload, array $vapid): array;

    /**
     * Par de claves VAPID nuevo (base64url).
     *
     * @return array{public_key: string, private_key: string}
     */
    public function createKeys(): array;
}
