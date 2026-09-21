<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Services\InboxService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ejecuta una acción de automatización reutilizando los módulos existentes o
 * realizando efectos externos (webhook). Devuelve un mensaje del resultado.
 */
class ActionExecutor
{
    public function __construct(private readonly InboxService $inbox)
    {
    }

    /**
     * @param  array{type: string, config?: array<string, mixed>}  $action
     * @param  array<string, mixed>  $context
     */
    public function execute(array $action, array $context): string
    {
        $type = AutomationActionType::tryFrom($action['type']);
        $config = $action['config'] ?? [];

        return match ($type) {
            AutomationActionType::NOTIFY => $this->notify($config, $context),
            AutomationActionType::WEBHOOK => $this->webhook($config, $context),
            AutomationActionType::INBOX_REPLY => $this->inboxReply($config, $context),
            AutomationActionType::INBOX_TAG => $this->inboxTag($config, $context),
            null => throw new RuntimeException('Acción desconocida: ' . $action['type']),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function notify(array $config, array $context): string
    {
        $message = $this->interpolate((string) ($config['message'] ?? 'Evento de automatización'), $context);

        return 'Notificación registrada: ' . $message;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function webhook(array $config, array $context): string
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('El webhook requiere una URL.');
        }

        $response = Http::timeout(10)->asJson()->post($url, [
            'trigger' => $context['trigger'] ?? null,
            'context' => $context,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('El webhook respondió ' . $response->status());
        }

        return 'Webhook llamado (' . $response->status() . ')';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function inboxReply(array $config, array $context): string
    {
        $conversation = $this->resolveConversation($context);
        $body = $this->interpolate((string) ($config['message'] ?? ''), $context);
        if (trim($body) === '') {
            throw new RuntimeException('La respuesta automática requiere un mensaje.');
        }

        $this->inbox->systemReply($conversation, $body);

        return 'Respuesta automática enviada';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function inboxTag(array $config, array $context): string
    {
        $conversation = $this->resolveConversation($context);
        $tag = trim((string) ($config['tag'] ?? ''));
        if ($tag === '') {
            throw new RuntimeException('El etiquetado requiere una etiqueta.');
        }

        $tags = array_values(array_unique([...($conversation->tags ?? []), $tag]));
        $conversation->update(['tags' => $tags]);

        return 'Etiqueta añadida: ' . $tag;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveConversation(array $context): InboxConversation
    {
        $publicId = (string) ($context['conversation_id'] ?? '');
        if ($publicId === '') {
            throw new RuntimeException('No hay conversación en el contexto.');
        }

        return InboxConversation::query()->withoutGlobalScopes()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Sustituye tokens {campo} por valores del contexto.
     *
     * @param  array<string, mixed>  $context
     */
    private function interpolate(string $template, array $context): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($context): string {
            return (string) ($context[$m[1]] ?? $m[0]);
        }, $template) ?? $template;
    }
}
