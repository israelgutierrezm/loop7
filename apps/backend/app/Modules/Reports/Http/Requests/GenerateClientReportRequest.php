<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Reports\Enums\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Periodo con que generar (o enviar) un informe: el suyo, otro predefinido o
 * fechas concretas (días completos, como mucho un año y nunca el futuro).
 */
class GenerateClientReportRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ANALYTICS_REPORTS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in([...ReportPeriod::values(), 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('period') !== 'custom') {
                    return;
                }
                $from = CarbonImmutable::parse((string) $this->input('from'));
                $to = CarbonImmutable::parse((string) $this->input('to'));
                if ($from->greaterThan($to)) {
                    $validator->errors()->add('from', 'La fecha de inicio debe ser anterior a la de fin.');
                } elseif ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'El periodo puede abarcar hasta un año.');
                } elseif ($to->greaterThan(CarbonImmutable::today()->addDay())) {
                    $validator->errors()->add('to', 'El periodo no puede terminar en el futuro.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required_if' => 'Elige la fecha de inicio.',
            'to.required_if' => 'Elige la fecha de fin.',
        ];
    }

    /**
     * Primer y último día del periodo en la zona horaria de la marca.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(ReportPeriod $default, string $timezone): array
    {
        $period = $this->validated('period');
        if ($period === 'custom') {
            return [
                CarbonImmutable::parse((string) $this->validated('from'), $timezone)->startOfDay(),
                CarbonImmutable::parse((string) $this->validated('to'), $timezone)->startOfDay(),
            ];
        }

        return (is_string($period) ? ReportPeriod::from($period) : $default)->range($timezone);
    }
}
