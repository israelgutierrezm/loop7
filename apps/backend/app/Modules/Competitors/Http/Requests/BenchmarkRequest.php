<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Competitors\Services\CompetitorBenchmark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Periodo y red de la comparación con la competencia.
 */
class BenchmarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ANALYTICS_VIEW);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'provider' => ['nullable', 'string', Rule::in(CompetitorBenchmark::PROVIDERS)],
        ];
    }

    public function days(): int
    {
        return (int) ($this->validated('days') ?? 30);
    }

    public function provider(): ?string
    {
        $provider = $this->validated('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }
}
