<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * Sólo se aceptan endpoints de los servicios push de los navegadores: el
 * endpoint lo envía el navegador del usuario y el servidor le hará peticiones,
 * así que una URL arbitraria (red interna, otro servicio) sería un SSRF.
 */
final class PushEndpoint
{
    /** Chrome, Edge (Chromium), Opera, Brave y Android: FCM; Firefox: Mozilla; Safari: Apple. */
    private const HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];

    /** Variantes regionales de Mozilla y Apple, y el servicio de Windows (WNS). */
    private const SUFFIXES = ['.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'];

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || strlen($endpoint) > 2048
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (in_array($host, self::HOSTS, true)) {
            return true;
        }
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
