<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Red que exige que la persona elija opciones antes de publicar (p. ej. la
 * privacidad y las interacciones en TikTok, según sus directrices): el editor
 * las pide y se guardan en `PostVariant::$options`, que llegan al adaptador en
 * `PublishPayload::$options`.
 */
interface ProvidesPublishOptions
{
    /**
     * Estado actual de la cuenta y opciones disponibles (se consulta en vivo al
     * abrir el editor).
     *
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    public function publishOptions(OAuthTokens $tokens, string $destinationExternalId, array $credentials): array;

    /**
     * Problemas de las opciones elegidas (vacío si se puede programar).
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function optionErrors(array $options): array;
}
