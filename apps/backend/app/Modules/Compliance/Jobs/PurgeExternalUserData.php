<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\DataDeletionRequest;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\SocialConnections\Models\SocialConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Borra los datos vinculados a un usuario externo (conexiones sociales cuya
 * cuenta le pertenece y conversaciones del inbox donde participa). Cumple la
 * solicitud de borrado de datos (Meta). Se ejecuta en todas las Organizations.
 */
class PurgeExternalUserData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $request = DataDeletionRequest::query()->find($this->requestId);
        if ($request === null || $request->status === 'completed') {
            return;
        }

        $userId = $request->external_user_id;
        $deleted = 0;

        // Conexiones sociales cuya cuenta externa pertenece al usuario.
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('external_account_id', $userId)->get();
        foreach ($connections as $connection) {
            $connection->forceDelete(); // cascada: destinos, conversaciones, targets
            $deleted++;
        }

        // Conversaciones del inbox donde el usuario es el participante.
        $conversations = InboxConversation::query()->withoutGlobalScopes()
            ->where('participant_external_id', $userId)->get();
        foreach ($conversations as $conversation) {
            $conversation->delete(); // cascada: mensajes
            $deleted++;
        }

        $request->update([
            'status' => 'completed',
            'deleted_items' => $deleted,
            'completed_at' => now(),
        ]);
    }
}
