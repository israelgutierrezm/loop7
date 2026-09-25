<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Providers\Meta;

use App\Modules\SocialConnections\Exceptions\SocialProviderException;
use App\Modules\SocialConnections\Exceptions\SocialTokenExpiredException;
use App\Support\Security\SecretRedactor;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente mínimo de Graph API compartido por Facebook e Instagram. La versión
 * sale de la configuración del proveedor (SUPERADMIN) o de
 * `services.meta.graph_version`; Meta retira cada versión ~2 años después.
 *
 * Graph recibe los tokens (y el app secret del intercambio OAuth) en la query:
 * un fallo de red nunca propaga el mensaje de cURL, que incluye la URL completa.
 */
final class MetaGraph
{
    private const TIMEOUT_SECONDS = 30;

    private const HOST = 'https://graph.facebook.com/';

    public function __construct(public readonly string $version)
    {
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public static function fromCredentials(array $credentials): self
    {
        $version = (string) ($credentials['graph_version'] ?? '');
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = (string) config('services.meta.graph_version', 'v25.0');
        }

        return new self($version);
    }

    public function url(string $path): string
    {
        return self::HOST . $this->version . '/' . ltrim($path, '/');
    }

    public function dialogUrl(): string
    {
        return 'https://www.facebook.com/' . $this->version . '/dialog/oauth';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, string $action = 'consultar los datos'): array
    {
        return $this->check($this->send(
            fn () => Http::timeout(self::TIMEOUT_SECONDS)->get($this->url($path), $query),
            $path,
            $action,
        ), $action);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function post(string $path, array $form, string $action): array
    {
        return $this->check($this->send(
            fn () => Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post($this->url($path), $form),
            $path,
            $action,
        ), $action);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $path, string $action): Response
    {
        try {
            return $request();
        } catch (ConnectionException|TransferException $e) {
            // Sin encadenar la excepción original: su mensaje lleva la URL con el token.
            Log::warning('Meta Graph: fallo de conexión.', [
                'path' => $path,
                'error' => SecretRedactor::redact($e->getMessage()),
            ]);

            throw new SocialProviderException(
                'No se pudo conectar con Meta para ' . $action . '. Inténtalo de nuevo en unos minutos.',
            );
        }
    }

    /**
     * Lee métricas de Insights tolerando las que Meta ya no ofrece: intenta todas
     * juntas y, si rechaza alguna, las consulta de una en una (las no disponibles
     * quedan en 0). Los errores de token sí se propagan.
     *
     * @param  list<string>  $metrics
     * @param  array<string, string>  $params
     * @return array<string, int>
     */
    public function insights(string $objectId, array $metrics, array $params, string $token): array
    {
        $values = array_fill_keys($metrics, 0);

        try {
            $data = $this->get($objectId . '/insights', [
                ...$params,
                'metric' => implode(',', $metrics),
                'access_token' => $token,
            ]);

            return array_replace($values, array_intersect_key(self::insightMap($data), $values));
        } catch (SocialTokenExpiredException $e) {
            throw $e;
        } catch (SocialProviderException) {
            // Alguna métrica no está disponible: se consultan por separado.
        }

        foreach ($metrics as $metric) {
            try {
                $data = $this->get($objectId . '/insights', [
                    ...$params,
                    'metric' => $metric,
                    'access_token' => $token,
                ]);
                $values = array_replace($values, array_intersect_key(self::insightMap($data), $values));
            } catch (SocialTokenExpiredException $e) {
                throw $e;
            } catch (SocialProviderException) {
                // Métrica no disponible para este objeto/versión.
            }
        }

        return $values;
    }

    /**
     * Normaliza la respuesta de Insights: admite series (`values[]`, se toma el
     * último valor) y totales (`total_value.value`).
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    public static function insightMap(array $response): array
    {
        $map = [];
        foreach ((array) ($response['data'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['name'])) {
                continue;
            }
            $name = (string) $item['name'];

            if (isset($item['total_value']['value']) && is_numeric($item['total_value']['value'])) {
                $map[$name] = (int) $item['total_value']['value'];

                continue;
            }

            $series = is_array($item['values'] ?? null) ? $item['values'] : [];
            $last = $series !== [] ? $series[array_key_last($series)] : null;
            $value = is_array($last) ? ($last['value'] ?? 0) : 0;
            $map[$name] = is_numeric($value) ? (int) $value : 0;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Response $response, string $action): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $error = (array) $response->json('error', []);
        $message = (string) ($error['message'] ?? ('HTTP ' . $response->status()));
        $code = (int) ($error['code'] ?? 0);

        // 190 = token inválido/caducado/revocado; 102 = sesión inválida.
        if ($code === 190 || $code === 102 || $response->status() === 401) {
            throw new SocialTokenExpiredException('Meta rechazó el token de acceso: ' . $message);
        }

        throw new SocialProviderException('Meta no permitió ' . $action . ': ' . $message);
    }
}
