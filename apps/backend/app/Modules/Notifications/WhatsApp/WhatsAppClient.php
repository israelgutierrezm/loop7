<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

use App\Modules\Notifications\Models\NotificationChannel;
use App\Modules\Notifications\Services\NotificationChannels;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Envío por WhatsApp Cloud API (Meta). Fuera de una conversación abierta por
 * el usuario sólo se admiten plantillas aprobadas: una de utilidad para los
 * avisos y una de autenticación para el código de verificación.
 */
class WhatsAppClient
{
    private const BASE = 'https://graph.facebook.com';

    private const TIMEOUT_SECONDS = 10;

    /** Meta rechaza variables con saltos de línea, tabuladores o más de 4 espacios seguidos. */
    private const MAX_PARAM = 300;

    public function __construct(private readonly NotificationChannels $channels)
    {
    }

    /**
     * @param  string  $to  número en formato E.164 (+5215512345678)
     * @param  list<string>  $bodyParams  variables {{1}}, {{2}}… del cuerpo
     * @param  string|null  $buttonParam  variable del botón (el código, en plantillas de autenticación)
     * @return string id del mensaje en WhatsApp
     *
     * @throws WhatsAppException
     */
    public function sendTemplate(string $to, string $template, array $bodyParams, ?string $buttonParam = null): string
    {
        $channel = $this->channel();

        $components = [[
            'type' => 'body',
            'parameters' => array_map(fn (string $text): array => ['type' => 'text', 'text' => self::param($text)], $bodyParams),
        ]];
        if ($buttonParam !== null) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $buttonParam]],
            ];
        }

        $response = $this->send(fn (PendingRequest $http): Response => $http->post(
            $this->url($channel->setting('phone_number_id') . '/messages'),
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => (string) preg_replace('/\D/', '', $to),
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $channel->setting('language') !== '' ? $channel->setting('language') : 'es_MX'],
                    'components' => $components,
                ],
            ],
        ), $channel);

        return (string) $response->json('messages.0.id', '');
    }

    /**
     * Datos del número emisor: sirve de prueba de conexión sin enviar mensajes.
     *
     * @return array{display_phone_number: string, verified_name: string, quality_rating: string}
     *
     * @throws WhatsAppException
     */
    public function phoneInfo(): array
    {
        $channel = $this->channel();
        $response = $this->send(fn (PendingRequest $http): Response => $http->get(
            $this->url($channel->setting('phone_number_id')),
            ['fields' => 'display_phone_number,verified_name,quality_rating'],
        ), $channel);

        return [
            'display_phone_number' => (string) $response->json('display_phone_number', ''),
            'verified_name' => (string) $response->json('verified_name', ''),
            'quality_rating' => (string) $response->json('quality_rating', ''),
        ];
    }

    private function channel(): NotificationChannel
    {
        $channel = $this->channels->get(NotificationChannel::WHATSAPP);

        // El id forma parte de la ruta: sólo dígitos (se valida también al guardarlo).
        if (preg_match('/^\d{5,20}$/', $channel->setting('phone_number_id')) !== 1 || $channel->secret('access_token') === '') {
            throw new WhatsAppException('Configura el número de WhatsApp y su token antes de enviar.');
        }

        return $channel;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request, NotificationChannel $channel): Response
    {
        try {
            $response = $request(Http::withToken($channel->secret('access_token'))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting());
        } catch (ConnectionException) {
            throw new WhatsAppException('No se pudo conectar con WhatsApp. Inténtalo más tarde.');
        }

        if (! $response->successful()) {
            throw WhatsAppException::fromResponse($response);
        }

        return $response;
    }

    private function url(string $path): string
    {
        return self::BASE . '/' . config('services.meta.graph_version', 'v25.0') . '/' . $path;
    }

    private static function param(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));

        return Str::limit($text !== '' ? $text : '—', self::MAX_PARAM);
    }
}
