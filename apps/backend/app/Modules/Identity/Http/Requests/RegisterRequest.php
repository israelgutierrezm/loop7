<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * Zona horaria del navegador para la cuenta y su organización. Es opcional y
     * nunca impide el alta: si falta o no es una zona IANA válida, queda UTC.
     */
    public function timezone(): string
    {
        $timezone = $this->input('timezone');

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato válido.',
            'email.unique' => 'Ya existe una cuenta con este correo.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'accept_terms.accepted' => 'Debes aceptar los términos y condiciones.',
        ];
    }
}
