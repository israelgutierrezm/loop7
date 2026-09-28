<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Requests;

use App\Modules\Webhooks\Enums\WebhookEvent;
use App\Support\Security\OutboundUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Alta (POST) y edición (PATCH, campos opcionales) de un endpoint. La URL debe
 * ser https y pública (anti-SSRF). Permiso y plan los comprueba el middleware
 * EnsureWebhooksEnabled.
 */
class SaveWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$required, 'string', 'max:2048', 'starts_with:https://'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEvent::subscribableValues())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.starts_with' => 'La URL debe empezar por https:// (los webhooks viajan cifrados).',
            'events.required' => 'Elige al menos un evento.',
            'events.min' => 'Elige al menos un evento.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $url = $this->input('url');
                if (! is_string($url) || $validator->errors()->has('url')) {
                    return;
                }

                try {
                    OutboundUrl::resolve($url);
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('url', $e->getMessage());
                }
            },
        ];
    }
}
