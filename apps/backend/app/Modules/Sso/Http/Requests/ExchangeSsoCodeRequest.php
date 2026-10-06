<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Canje del código de un solo uso que dejó el ACS, con el verificador del navegador.
 */
class ExchangeSsoCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:64', 'alpha_num'],
            'verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function verifier(): string
    {
        return (string) $this->validated('verifier');
    }
}
