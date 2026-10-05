<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Push\PushEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suscripción Web Push tal como la da el navegador (PushSubscription.toJSON()).
 * El endpoint debe ser de un servicio push conocido (anti-SSRF) y las claves,
 * las de un navegador real: P-256 sin comprimir (65 bytes) y secreto de 16.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PushEndpoint::allowed($value)) {
                    $fail('Este navegador no usa un servicio de avisos push compatible.');
                }
            }],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255', $this->base64Url(65)],
            'keys.auth' => ['required', 'string', 'max:255', $this->base64Url(16)],
        ];
    }

    private function base64Url(int $bytes): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($bytes): void {
            $decoded = is_string($value) && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) === 1
                ? base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true)
                : false;

            // Una clave P-256 sin comprimir empieza por 0x04.
            if ($decoded === false || strlen($decoded) !== $bytes || ($bytes === 65 && $decoded[0] !== "\x04")) {
                $fail('La suscripción del navegador no es válida.');
            }
        };
    }
}
