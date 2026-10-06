<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inicio de sesión con SSO a partir del correo. `challenge` es el SHA-256 (hex)
 * de un verificador que el navegador guarda y presentará al canjear el código.
 */
class DiscoverSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'challenge' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo de trabajo.',
            'email.email' => 'Escribe un correo válido.',
        ];
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }

    public function challenge(): string
    {
        return (string) $this->validated('challenge');
    }
}
