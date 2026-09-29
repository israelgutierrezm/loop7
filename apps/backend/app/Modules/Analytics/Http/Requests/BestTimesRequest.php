<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\SocialConnections\Services\SocialProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mejores horarios: redes a considerar y rango de las fechas sugeridas. Permiso
 * y plan los comprueba el controlador tras resolver la marca.
 */
class BestTimesRequest extends FormRequest
{
    /** Rango por defecto de las fechas sugeridas: la próxima semana. */
    private const DEFAULT_RANGE_DAYS = 7;

    /** Rango máximo (el mismo que el calendario). */
    private const MAX_RANGE_DAYS = 62;

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
            'providers' => ['sometimes', 'array', 'max:20'],
            'providers.*' => ['string', 'distinct', Rule::in(array_keys(app(SocialProviderManager::class)->all()))],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'providers.*.in' => 'Red social desconocida.',
        ];
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        $value = $this->validated('providers', []);
        if (! is_array($value)) {
            return [];
        }

        $providers = [];
        foreach ($value as $provider) {
            if (is_string($provider)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $from = $this->filled('from') ? CarbonImmutable::parse($this->string('from')->toString()) : CarbonImmutable::now();
        $to = $this->filled('to') ? CarbonImmutable::parse($this->string('to')->toString()) : $from->addDays(self::DEFAULT_RANGE_DAYS);

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to, true) > self::MAX_RANGE_DAYS) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }

        return [$from, $to];
    }
}
