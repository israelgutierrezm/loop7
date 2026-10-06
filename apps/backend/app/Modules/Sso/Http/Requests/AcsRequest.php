<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use App\Modules\Sso\Exceptions\SsoException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Respuesta del IdP (HTTP-POST binding). La envía el navegador desde el IdP:
 * los errores vuelven a la pantalla de acceso del SPA, no como JSON.
 */
class AcsRequest extends FormRequest
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
            // Una respuesta firmada ocupa unos KB; el límite evita cargas abusivas.
            'SAMLResponse' => ['required', 'string', 'max:1000000'],
            'RelayState' => ['required', 'string', 'max:80'],
        ];
    }

    public function samlResponse(): string
    {
        return (string) $this->validated('SAMLResponse');
    }

    public function relayState(): string
    {
        return (string) $this->validated('RelayState');
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(redirect()->away(
            rtrim((string) config('app.frontend_url'), '/') . '/login?sso_error=' . SsoException::INVALID_RESPONSE,
            303,
        ));
    }
}
