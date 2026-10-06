<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Services\AutomationGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de una automatización desde el editor visual. Aquí se valida
 * la forma; el flujo (pasos, límites y configuración de cada acción) lo valida
 * FlowValidator, que señala los errores por paso.
 */
class SaveAutomationRequest extends FormRequest
{
    /** Permiso y plan antes que la validación: sin ellos, 403/402 aunque los datos sean inválidos. */
    public function authorize(): bool
    {
        app(AutomationGate::class)->ensure(
            $this->user(),
            $this->isMethod('POST') ? Permission::AUTOMATIONS_CREATE : Permission::AUTOMATIONS_UPDATE,
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['boolean'],
            'trigger' => ['required', Rule::in(AutomationTrigger::values())],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.feed_url' => ['nullable', 'string', 'max:2048'],
            'brand' => ['nullable', 'string', 'max:26'],
            'flow' => ['required', 'array'],
            'flow.steps' => ['present', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la automatización.',
            'flow.required' => 'Añade al menos una acción.',
        ];
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::from((string) $this->validated('trigger'));
    }
}
