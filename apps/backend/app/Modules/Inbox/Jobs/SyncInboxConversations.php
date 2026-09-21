<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Jobs;

use App\Modules\Inbox\Services\InboxService;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Sincroniza las conversaciones del inbox de un destino. Reintentable; el
 * proveedor sin configurar se omite sin fallar.
 */
class SyncInboxConversations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $destinationId)
    {
        $this->onQueue('inbox');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-inbox-' . $this->destinationId))->dontRelease()];
    }

    public function handle(InboxService $inbox): void
    {
        $destination = SocialConnectionDestination::query()->withoutGlobalScopes()->find($this->destinationId);

        if ($destination !== null) {
            $inbox->syncDestination($destination);
        }
    }
}
