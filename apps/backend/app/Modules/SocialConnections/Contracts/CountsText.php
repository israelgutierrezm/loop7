<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Red que no cuenta los caracteres uno a uno (X: las URLs cuentan 23 y los
 * emojis y caracteres CJK, 2). El límite de `HasPublishingLimits::text` se
 * compara con esta longitud.
 */
interface CountsText
{
    public function textLength(string $text): int;
}
