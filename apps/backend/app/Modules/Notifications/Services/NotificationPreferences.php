<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationCategory;

/**
 * Preferencias de aviso por correo de cada usuario. Sólo se guardan las
 * elecciones explícitas; lo demás toma el valor por defecto de la categoría.
 */
class NotificationPreferences
{
    public function mailEnabled(User $user, NotificationCategory $category): bool
    {
        $mail = $user->notification_preferences['mail'] ?? [];

        return array_key_exists($category->value, $mail)
            ? (bool) $mail[$category->value]
            : $category->mailByDefault();
    }

    /**
     * @return array<string, bool>
     */
    public function mailSettings(User $user): array
    {
        $settings = [];
        foreach (NotificationCategory::cases() as $category) {
            $settings[$category->value] = $this->mailEnabled($user, $category);
        }

        return $settings;
    }

    /**
     * @param  array<string, bool>  $mail  categoría => recibir por correo
     */
    public function update(User $user, array $mail): void
    {
        $preferences = $user->notification_preferences ?? [];
        $known = array_intersect_key($mail, array_flip(NotificationCategory::values()));

        $preferences['mail'] = array_merge(
            $preferences['mail'] ?? [],
            array_map(fn ($enabled): bool => (bool) $enabled, $known),
        );

        $user->forceFill(['notification_preferences' => $preferences])->save();
    }
}
