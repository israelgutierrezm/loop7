<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Http\Requests;

use App\Modules\AccessControl\Permissions\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nueva cuenta de un competidor (se busca en su red antes de guardarla).
 */
class AddCompetitorAccountRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:32'],
            'handle' => ['required', 'string', 'max:200'],
        ];
    }
}
