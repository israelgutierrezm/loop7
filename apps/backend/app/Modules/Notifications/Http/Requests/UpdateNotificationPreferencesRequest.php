<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\DeliveryChannel;
use App\Modules\Notifications\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Elecciones por canal y categoría: {"mail": {"billing": false}, "push": {…}}.
 * Las categorías desconocidas no llegan al resultado validado.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $channels = DeliveryChannel::values();
        $rules = [];

        foreach ($channels as $channel) {
            $others = implode(',', array_diff($channels, [$channel]));
            $rules[$channel] = ["required_without_all:{$others}", 'array'];
            foreach (NotificationCategory::values() as $category) {
                $rules["{$channel}.{$category}"] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function choices(): array
    {
        /** @var array<string, array<string, bool>> $choices */
        $choices = array_intersect_key($this->validated(), array_flip(DeliveryChannel::values()));

        return $choices;
    }
}
