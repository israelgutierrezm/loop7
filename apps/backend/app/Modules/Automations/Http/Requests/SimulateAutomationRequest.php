<?php

declare(strict_types=1);

namespace App\Modules\Automations\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Services\AutomationGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Probar» el flujo del editor con datos de ejemplo (sin ejecutar nada).
 */
class SimulateAutomationRequest extends FormRequest
{
    private const MAX_FIELDS = 100;

    public function authorize(): bool
    {
        app(AutomationGate::class)->ensure($this->user(), Permission::AUTOMATIONS_VIEW);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trigger' => ['required', Rule::in(AutomationTrigger::values())],
            'brand' => ['nullable', 'string', 'max:26'],
            'flow' => ['required', 'array'],
            'flow.steps' => ['present', 'array'],
            'context' => ['nullable', 'array', 'max:' . self::MAX_FIELDS],
            'context.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::from((string) $this->validated('trigger'));
    }

    /**
     * Datos de ejemplo (campo => valor); sin ellos, los del disparador.
     *
     * @return array<string, string>
     */
    public function sampleContext(): array
    {
        $context = [];
        foreach ((array) $this->input('context', []) as $field => $value) {
            if (is_string($field) && preg_match('/^[\w.-]{1,60}$/u', $field) === 1) {
                $context[$field] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $context !== [] ? $context : $this->trigger()->examples();
    }
}
