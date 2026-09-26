<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nombre, descripción y permisos de un rol personalizado. Las reglas de
 * negocio (plan, permisos que se pueden conceder, nombres) viven en
 * CustomRoleService.
 */
class SaveCustomRoleRequest extends FormRequest
{
    /** Antes que la validación: sin permiso, 403 aunque los datos sean inválidos. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->isMethod('POST') ? Permission::ROLES_CREATE : Permission::ROLES_UPDATE);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['required', 'array', 'min:1', 'max:' . count(Permission::all())],
            'permissions.*' => ['string', Rule::in(Permission::all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.required' => 'Elige al menos un permiso.',
            'permissions.min' => 'Elige al menos un permiso.',
            'permissions.*.in' => 'Uno de los permisos no existe.',
        ];
    }
}
