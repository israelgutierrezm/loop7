<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Qué avisos recibe el usuario también por correo (en la app llegan siempre).
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $rules = ['mail' => ['required', 'array']];
        foreach (NotificationCategory::values() as $category) {
            $rules["mail.{$category}"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);

        // Las categorías desconocidas no llegan al resultado validado.
        $this->preferences->update($request->user(), $data['mail'] ?? []);

        return ApiResponse::success($this->payload($request), 'Preferencias guardadas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $mail = $this->preferences->mailSettings($request->user());

        return [
            'categories' => array_map(fn (NotificationCategory $c) => [
                'key' => $c->value,
                'label' => $c->label(),
                'description' => $c->description(),
                'mail' => $mail[$c->value],
            ], NotificationCategory::cases()),
        ];
    }
}
