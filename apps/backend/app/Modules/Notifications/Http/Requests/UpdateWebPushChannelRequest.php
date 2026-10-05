<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activación de los avisos push y contacto del operador (VAPID "sub", RFC 8292).
 */
class UpdateWebPushChannelRequest extends FormRequest
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
        return [
            'is_enabled' => ['required', 'boolean'],
            'subject' => ['nullable', 'string', 'max:255', 'regex:/^(mailto:[^\s@]+@[^\s@]+|https:\/\/\S+)$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.regex' => 'Usa un correo (mailto:soporte@tudominio.com) o una URL https.',
        ];
    }
}
