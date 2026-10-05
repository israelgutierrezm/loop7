<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

/**
 * Números de teléfono en formato internacional E.164 (+ y hasta 15 dígitos).
 */
final class WhatsAppNumber
{
    /**
     * Quita espacios, guiones, puntos y paréntesis; null si no es E.164.
     */
    public static function normalize(string $input): ?string
    {
        $phone = (string) preg_replace('/[\s\-().]/', '', $input);

        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1 ? $phone : null;
    }

    /**
     * Para mostrarlo sin exponerlo entero: "+52 ••• 5678".
     */
    public static function mask(string $phone): string
    {
        return substr($phone, 0, 3) . ' ••• ' . substr($phone, -4);
    }
}
