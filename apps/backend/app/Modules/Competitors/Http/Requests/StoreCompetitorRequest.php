<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use App\Modules\Competitors\Services\CompetitorService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de un competidor con sus cuentas (cada cuenta se busca en su red).
 */
class StoreCompetitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ANALYTICS_COMPETITORS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'accounts' => ['required', 'array', 'min:1', 'max:' . CompetitorService::MAX_ACCOUNTS_PER_COMPETITOR],
            'accounts.*.provider' => ['required', 'string', 'max:32'],
            'accounts.*.handle' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del competidor.',
            'accounts.required' => 'Añade al menos una cuenta.',
            'accounts.*.handle.required' => 'Escribe la cuenta.',
        ];
    }

    /**
     * @return list<array{provider: string, handle: string}>
     */
    public function accounts(): array
    {
        return array_values(array_map(
            fn (array $a): array => ['provider' => (string) $a['provider'], 'handle' => (string) $a['handle']],
            (array) $this->validated('accounts'),
        ));
    }
}
