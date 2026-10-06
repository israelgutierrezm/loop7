<?php

declare(strict_types=1);

namespace App\Modules\Sso\Http\Requests;

use App\Modules\Sso\Http\Requests\Concerns\AuthorizesSsoSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Metadatos XML del IdP pegados por quien administra (no se descargan de una
 * URL: así el servidor nunca hace peticiones a direcciones arbitrarias).
 */
class IdpMetadataRequest extends FormRequest
{
    use AuthorizesSsoSettings;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'xml' => ['required', 'string', 'max:500000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'xml.required' => 'Pega los metadatos XML de tu proveedor de identidad.',
            'xml.max' => 'Los metadatos son demasiado grandes.',
        ];
    }
}
