<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use App\Modules\Sso\Http\Requests\Concerns\AuthorizesSsoSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de un dominio de correo de la organización (se normaliza y valida en SsoDomains).
 */
class SsoDomainRequest extends FormRequest
{
    use AuthorizesSsoSettings;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Escribe el dominio, por ejemplo empresa.com.',
        ];
    }
}
