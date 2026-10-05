<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\DeliveryChannel;
use App\Modules\Notifications\Enums\NotificationCategory;

/**
 * Preferencias de aviso de cada usuario por canal (correo, push, WhatsApp) y
 * categoría. Sólo se guardan las elecciones explícitas; lo demás toma el valor
 * por defecto de la categoría en ese canal.
 */
class NotificationPreferences
{
    public function enabled(User $user, DeliveryChannel $channel, NotificationCategory $category): bool
    {
        $choices = $user->notification_preferences[$channel->value] ?? [];

        return array_key_exists($category->value, $choices)
            ? (bool) $choices[$category->value]
            : $category->enabledByDefault($channel);
    }

    public function mailEnabled(User $user, NotificationCategory $category): bool
    {
        return $this->enabled($user, DeliveryChannel::MAIL, $category);
    }

    /**
     * @return array<string, array<string, bool>> categoría => canal => recibir
     */
    public function settings(User $user): array
    {
        $settings = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (DeliveryChannel::cases() as $channel) {
                $settings[$category->value][$channel->value] = $this->enabled($user, $channel, $category);
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, array<string, bool>>  $choices  canal => categoría => recibir
     */
    public function update(User $user, array $choices): void
    {
        $preferences = $user->notification_preferences ?? [];
        $categories = array_flip(NotificationCategory::values());

        foreach (DeliveryChannel::cases() as $channel) {
            if (! isset($choices[$channel->value])) {
                continue;
            }
            $known = array_intersect_key($choices[$channel->value], $categories);
            $preferences[$channel->value] = array_merge(
                $preferences[$channel->value] ?? [],
                array_map(fn ($enabled): bool => (bool) $enabled, $known),
            );
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();
    }
}
