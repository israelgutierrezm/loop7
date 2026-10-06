<?php

declare(strict_types=1);

namespace App\Modules\Automations\Flow;

use App\Models\User;
use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Enums\ConditionMatch;
use App\Modules\Automations\Enums\ConditionOperator;
use App\Modules\Automations\Enums\FlowStepType;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Enums\WaitUnit;
use App\Support\Security\OutboundUrl;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Valida y normaliza el flujo que llega del editor visual: estructura, límites
 * y la configuración de cada paso, para no descubrir el error cuando la regla
 * ya se está ejecutando. Los errores se indican por paso
 * (`flow.{id}.{campo}`) para señalarlos en el diagrama; los generales, en `flow`.
 */
final class FlowValidator
{
    /** Campos que guarda cada tipo de acción. */
    private const ACTION_FIELDS = [
        'notify' => ['message', 'audience'],
        'webhook' => ['url'],
        'create_draft' => ['title', 'body'],
        'inbox_reply' => ['message'],
        'inbox_tag' => ['tag'],
    ];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $ids = [];

    private AutomationTrigger $trigger;

    private bool $hasBrand;

    private User $user;

    /**
     * @param  mixed  $input  {steps: [...]}
     *
     * @throws ValidationException
     */
    public function validate(mixed $input, AutomationTrigger $trigger, bool $hasBrand, User $user): AutomationFlow
    {
        $this->errors = [];
        $this->ids = [];
        $this->trigger = $trigger;
        $this->hasBrand = $hasBrand;
        $this->user = $user;

        $steps = is_array($input) ? ($input['steps'] ?? null) : null;
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw ValidationException::withMessages(['flow' => 'El flujo no es válido.']);
        }

        $flow = AutomationFlow::fromSteps($this->list($steps, 0));
        $all = $flow->all();
        $count = fn (FlowStepType $type): int => count(array_filter($all, fn (array $s): bool => $s['type'] === $type->value));

        if (count($all) > AutomationFlow::MAX_STEPS) {
            $this->fail('flow', 'El flujo admite hasta ' . AutomationFlow::MAX_STEPS . ' pasos.');
        }
        if ($count(FlowStepType::ACTION) > AutomationFlow::MAX_ACTIONS) {
            $this->fail('flow', 'El flujo admite hasta ' . AutomationFlow::MAX_ACTIONS . ' acciones.');
        }
        if ($count(FlowStepType::WAIT) > AutomationFlow::MAX_WAITS) {
            $this->fail('flow', 'El flujo admite hasta ' . AutomationFlow::MAX_WAITS . ' esperas.');
        }
        if ($count(FlowStepType::ACTION) === 0) {
            $this->fail('flow', 'Añade al menos una acción.');
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $flow;
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return list<array<string, mixed>>
     */
    private function list(array $steps, int $depth): array
    {
        $normalized = [];
        $count = count($steps);

        foreach (array_values($steps) as $position => $step) {
            if (! is_array($step)) {
                $this->fail('flow', 'El flujo no es válido.');

                continue;
            }

            $id = $this->stepId($step);
            $type = FlowStepType::tryFrom((string) ($step['type'] ?? ''));
            $isLast = $position === $count - 1;

            $normalized[] = match ($type) {
                FlowStepType::ACTION => $this->action($id, $step),
                FlowStepType::WAIT => $this->wait($id, $step, $isLast),
                FlowStepType::BRANCH => $this->branch($id, $step, $depth, $isLast),
                null => $this->invalid($id),
            };
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepId(array $step): string
    {
        $id = $step['id'] ?? null;
        if ($id === null || $id === '') {
            $id = 's_' . Str::lower(Str::random(10));
        }

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) !== 1) {
            $this->fail('flow', 'Un paso tiene un identificador no válido.');

            return 's_' . Str::lower(Str::random(10));
        }
        if (isset($this->ids[$id])) {
            $this->fail('flow', 'Hay pasos repetidos en el flujo.');
        }
        $this->ids[$id] = true;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $id): array
    {
        $this->fail("flow.{$id}", 'Tipo de paso desconocido.');

        return ['id' => $id, 'type' => 'invalid'];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function action(string $id, array $step): array
    {
        $type = AutomationActionType::tryFrom((string) ($step['action'] ?? ''));
        $raw = is_array($step['config'] ?? null) ? $step['config'] : [];

        if ($type === null) {
            $this->fail("flow.{$id}.action", 'Elige qué acción hacer.');

            return ['id' => $id, 'type' => FlowStepType::ACTION->value, 'action' => '', 'config' => []];
        }

        // Sólo los campos de ese tipo de acción, como texto recortado.
        $config = [];
        foreach (self::ACTION_FIELDS[$type->value] as $field) {
            $value = $raw[$field] ?? '';
            $config[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        $this->actionConfig($id, $type, $config);
        if ($type === AutomationActionType::NOTIFY && $config['audience'] === '') {
            $config['audience'] = NotifyAudience::MANAGERS->value;
        }

        return ['id' => $id, 'type' => FlowStepType::ACTION->value, 'action' => $type->value, 'config' => $config];
    }

    /**
     * @param  array<string, string>  $config
     */
    private function actionConfig(string $id, AutomationActionType $type, array $config): void
    {
        $key = "flow.{$id}";

        if (! $type->allowsTrigger($this->trigger)) {
            $this->fail("{$key}.action", "«{$type->label()}» sólo funciona con mensajes del inbox.");

            return;
        }

        switch ($type) {
            case AutomationActionType::NOTIFY:
                if ($config['message'] === '' || mb_strlen($config['message']) > 1000) {
                    $this->fail("{$key}.message", 'Escribe el mensaje del aviso (máx. 1000 caracteres).');
                }
                if ($config['audience'] !== '' && NotifyAudience::tryFrom($config['audience']) === null) {
                    $this->fail("{$key}.audience", 'Elige a quién avisar.');
                }
                break;
            case AutomationActionType::WEBHOOK:
                // Anti-SSRF: sólo servidores públicos (se revalida al ejecutar).
                try {
                    OutboundUrl::resolve($config['url']);
                } catch (InvalidArgumentException $e) {
                    $this->fail("{$key}.url", $e->getMessage());
                }
                break;
            case AutomationActionType::CREATE_DRAFT:
                // Crear contenido exige poder crearlo (sin escaladas vía automatización).
                if (! $this->user->can(Permission::CONTENT_CREATE)) {
                    $this->fail("{$key}.action", 'No tienes permiso para crear contenido.');
                    break;
                }
                if (! $this->hasBrand) {
                    $this->fail("{$key}.action", 'Elige la marca donde se crearán los borradores.');
                }
                if ($config['title'] === '' || mb_strlen($config['title']) > 255) {
                    $this->fail("{$key}.title", 'Escribe el título del borrador (máx. 255 caracteres).');
                }
                if (mb_strlen($config['body']) > 5000) {
                    $this->fail("{$key}.body", 'El texto del borrador admite hasta 5000 caracteres.');
                }
                break;
            case AutomationActionType::INBOX_REPLY:
                if ($config['message'] === '' || mb_strlen($config['message']) > 2000) {
                    $this->fail("{$key}.message", 'Escribe la respuesta automática (máx. 2000 caracteres).');
                }
                break;
            case AutomationActionType::INBOX_TAG:
                if ($config['tag'] === '' || mb_strlen($config['tag']) > 40) {
                    $this->fail("{$key}.tag", 'Indica la etiqueta (máx. 40 caracteres).');
                }
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function wait(string $id, array $step, bool $isLast): array
    {
        $unit = WaitUnit::tryFrom((string) ($step['unit'] ?? '')) ?? WaitUnit::HOURS;
        $amount = filter_var($step['amount'] ?? null, FILTER_VALIDATE_INT);

        if ($amount === false || $amount < 1 || $unit->seconds($amount) > AutomationFlow::MAX_WAIT_DAYS * 86400) {
            $this->fail("flow.{$id}.amount", 'Indica cuánto esperar: de 1 minuto a ' . AutomationFlow::MAX_WAIT_DAYS . ' días.');
            $amount = 1;
        }
        // Una espera al final no espera a nada.
        if ($isLast) {
            $this->fail("flow.{$id}", 'Añade un paso después de la espera.');
        }

        return ['id' => $id, 'type' => FlowStepType::WAIT->value, 'amount' => $amount, 'unit' => $unit->value];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function branch(string $id, array $step, int $depth, bool $isLast): array
    {
        $key = "flow.{$id}";

        if ($depth >= AutomationFlow::MAX_DEPTH) {
            $this->fail($key, 'Demasiadas condiciones anidadas (máx. ' . AutomationFlow::MAX_DEPTH . ').');
        }
        // Lo que viene después de una condición va dentro de «Sí» o de «No».
        if (! $isLast) {
            $this->fail($key, 'La condición debe ser el último paso de su camino.');
        }

        $match = ConditionMatch::tryFrom((string) ($step['match'] ?? '')) ?? ConditionMatch::ALL;
        $raw = is_array($step['conditions'] ?? null) ? array_values($step['conditions']) : [];
        if ($raw === [] || count($raw) > AutomationFlow::MAX_CONDITIONS) {
            $this->fail("{$key}.conditions", 'Añade de 1 a ' . AutomationFlow::MAX_CONDITIONS . ' condiciones.');
        }

        $conditions = [];
        foreach (array_slice($raw, 0, AutomationFlow::MAX_CONDITIONS) as $i => $condition) {
            $conditions[] = $this->condition("{$key}.conditions.{$i}", is_array($condition) ? $condition : []);
        }

        $yes = is_array($step['yes'] ?? null) ? $this->list($step['yes'], $depth + 1) : [];
        $no = is_array($step['no'] ?? null) ? $this->list($step['no'], $depth + 1) : [];

        return [
            'id' => $id,
            'type' => FlowStepType::BRANCH->value,
            'match' => $match->value,
            'conditions' => $conditions,
            'yes' => $yes,
            'no' => $no,
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{field: string, operator: string, value: string}
     */
    private function condition(string $key, array $condition): array
    {
        $field = trim(is_scalar($condition['field'] ?? null) ? (string) $condition['field'] : '');
        $operator = ConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));
        $value = is_scalar($condition['value'] ?? null) ? trim((string) $condition['value']) : '';

        // Campos del disparador o del JSON recibido (cliente.email).
        if (preg_match('/^[\w.-]{1,60}$/u', $field) !== 1) {
            $this->fail("{$key}.field", 'Elige el campo que se compara.');
        }
        if ($operator === null) {
            $this->fail("{$key}.operator", 'Elige cómo se compara.');
        }
        if (mb_strlen($value) > 255) {
            $this->fail("{$key}.value", 'El valor admite hasta 255 caracteres.');
        }

        return [
            'field' => $field,
            'operator' => $operator !== null ? $operator->value : '',
            'value' => $operator !== null && ! $operator->needsValue() ? '' : $value,
        ];
    }

    private function fail(string $key, string $message): void
    {
        $this->errors[$key] ??= $message;
    }
}
