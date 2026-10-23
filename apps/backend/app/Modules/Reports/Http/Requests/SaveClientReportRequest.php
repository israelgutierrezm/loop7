<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Reports\Enums\ReportFrequency;
use App\Modules\Reports\Enums\ReportPeriod;
use App\Modules\Reports\Enums\ReportSection;
use App\Modules\Reports\Services\ClientReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta o cambio de un informe para clientes. Los destinatarios se normalizan
 * (minúsculas, sin repetidos) y el envío automático exige al menos uno.
 */
class SaveClientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ANALYTICS_REPORTS);
    }

    protected function prepareForValidation(): void
    {
        $recipients = $this->input('recipients');
        $this->merge([
            // Sin saltos de línea: el nombre va en el asunto del correo.
            'name' => is_string($this->input('name')) ? trim((string) preg_replace('/\s+/u', ' ', $this->input('name'))) : $this->input('name'),
            'recipients' => is_array($recipients)
                ? array_values(array_unique(array_map(fn ($r) => is_string($r) ? mb_strtolower(trim($r)) : $r, $recipients)))
                : $recipients,
            'intro' => is_string($this->input('intro')) && trim($this->input('intro')) === '' ? null : $this->input('intro'),
            'frequency' => $this->input('frequency') === '' ? null : $this->input('frequency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $frequency = ReportFrequency::tryFrom((string) $this->input('frequency'));

        return [
            'name' => ['required', 'string', 'max:120'],
            'period' => ['required', 'string', Rule::in(ReportPeriod::values())],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'string', 'distinct', Rule::in(ReportSection::values())],
            'intro' => ['nullable', 'string', 'max:2000'],
            'recipients' => ['present', 'array', 'max:' . ClientReportService::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'max:255', 'email:rfc'],
            'frequency' => ['nullable', 'string', Rule::enum(ReportFrequency::class)],
            'send_day' => ['nullable', 'required_with:frequency', 'integer', 'min:1', 'max:' . ($frequency?->maxDay() ?? 28)],
            'send_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->input('frequency') !== null && $this->input('recipients') === []) {
                    $validator->errors()->add('recipients', 'Añade al menos un destinatario para el envío automático.');
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
            'name.required' => 'Ponle un nombre al informe.',
            'sections.required' => 'Elige al menos una sección.',
            'sections.min' => 'Elige al menos una sección.',
            'recipients.max' => 'Puedes añadir hasta ' . ClientReportService::MAX_RECIPIENTS . ' destinatarios.',
            'recipients.*.email' => 'Revisa los correos de los destinatarios.',
            'send_day.required_with' => 'Elige el día del envío.',
            'send_day.max' => 'Elige un día válido.',
        ];
    }

    /**
     * @return array{name: string, period: string, sections: list<string>, intro: string|null, recipients: list<string>, frequency: string|null, send_day: int|null, send_hour: int}
     */
    public function report(): array
    {
        $frequency = $this->validated('frequency');
        // Las secciones en el orden del PDF.
        $sections = array_values(array_filter(ReportSection::values(), fn (string $s): bool => in_array($s, (array) $this->validated('sections'), true)));

        return [
            'name' => (string) $this->validated('name'),
            'period' => (string) $this->validated('period'),
            'sections' => $sections,
            'intro' => $this->validated('intro'),
            'recipients' => array_values((array) $this->validated('recipients')),
            'frequency' => $frequency,
            'send_day' => $frequency !== null ? (int) $this->validated('send_day') : null,
            'send_hour' => (int) ($this->validated('send_hour') ?? 8),
        ];
    }
}
