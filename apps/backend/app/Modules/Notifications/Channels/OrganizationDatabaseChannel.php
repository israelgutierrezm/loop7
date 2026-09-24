<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Notifications\OrganizationNotice;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Canal "database" que guarda además la Organization del aviso: un usuario
 * puede pertenecer a varias y la campana sólo muestra los de la actual.
 */
class OrganizationDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'organization_id' => $notification instanceof OrganizationNotice ? $notification->organizationId : null,
        ];
    }
}
