<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Events;

use App\Modules\SocialConnections\Models\SocialConnection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una conexión dejó de funcionar (token caducado o revocado) y hay que
 * reconectarla para seguir publicando.
 */
class SocialConnectionExpired
{
    use Dispatchable;

    public function __construct(public readonly SocialConnection $connection)
    {
    }
}
