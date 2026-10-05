<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\WhatsApp\WhatsAppNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Número de WhatsApp en formato internacional; se aceptan espacios y guiones.
 */
class StartWhatsAppVerificationRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || WhatsAppNumber::normalize($value) === null) {
                    $fail('Escribe el número con el código de país, por ejemplo +52 55 1234 5678.');
                }
            }],
        ];
    }

    public function phone(): string
    {
        return (string) WhatsAppNumber::normalize((string) $this->validated('phone'));
    }
}
