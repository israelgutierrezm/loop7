<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Número emisor y plantillas de WhatsApp Cloud API. El token es de solo
 * escritura: vacío conserva el guardado.
 */
class UpdateWhatsAppChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isPlatformAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = ['nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'];

        return [
            'is_enabled' => ['required', 'boolean'],
            // Forma parte de la URL de la API: sólo dígitos.
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d{5,20}$/'],
            'access_token' => ['nullable', 'string', 'max:1024'],
            'notice_template' => $template,
            'verification_template' => $template,
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number_id.regex' => 'El identificador del número son sólo dígitos (lo ves en WhatsApp Manager → API).',
            'notice_template.regex' => 'El nombre de la plantilla sólo lleva minúsculas, números y guiones bajos.',
            'verification_template.regex' => 'El nombre de la plantilla sólo lleva minúsculas, números y guiones bajos.',
            'language.regex' => 'Usa el código de idioma de la plantilla, por ejemplo es_MX o es.',
        ];
    }
}
