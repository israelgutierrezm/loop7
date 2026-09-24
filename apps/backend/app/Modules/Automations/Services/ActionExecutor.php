<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationActionType;
use App\Modules\Automations\Enums\NotifyAudience;
use App\Modules\Automations\Models\Automation;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Inbox\Services\InboxService;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Notifications\Services\Notifier;
use App\Support\Security\OutboundUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ejecuta una acción de automatización reutilizando los módulos existentes
 * (avisos, inbox) o realizando efectos externos (webhook). Devuelve un mensaje
 * del resultado.
 */
class ActionExecutor
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @param  array{type: string, config?: array<string, mixed>}  $action
     * @param  array<string, mixed>  $context
     * @param  int|null  $brandId  Brand del evento que disparó la regla
     */
    public function execute(array $action, array $context, Automation $automation, ?int $brandId = null): string
    {
        $type = AutomationActionType::tryFrom($action['type']);
        $config = $action['config'] ?? [];

        return match ($type) {
            AutomationActionType::NOTIFY => $this->notify($config, $context, $automation, $brandId ?? $automation->brand_id),
            AutomationActionType::WEBHOOK => $this->webhook($config, $context),
            AutomationActionType::INBOX_REPLY => $this->inboxReply($config, $context),
            AutomationActionType::INBOX_TAG => $this->inboxTag($config, $context),
            null => throw new RuntimeException('Acción desconocida: ' . $action['type']),
        };
    }

    /**
     * Aviso in-app (y por correo, según preferencias) a la audiencia elegida
     * con acceso a la marca del evento.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function notify(array $config, array $context, Automation $automation, ?int $brandId): string
    {
        $message = trim($this->interpolate((string) ($config['message'] ?? ''), $context));
        if ($message === '') {
            throw new RuntimeException('El aviso requiere un mensaje.');
        }

        $audience = NotifyAudience::tryFrom((string) ($config['audience'] ?? '')) ?? NotifyAudience::MANAGERS;

        $sent = $this->notifier->toMembersWithPermission(
            $audience->permission(),
            new OrganizationNotice(
                organizationId: $automation->organization_id,
                kind: 'automation.notify',
                category: NotificationCategory::AUTOMATIONS,
                title: $automation->name,
                body: Str::limit($message, 500),
                path: $this->pathFor($context),
            ),
            brandId: $brandId,
        );

        return "Aviso enviado a {$sent} persona(s)";
    }

    /**
     * Pantalla relacionada con el evento, si la hay.
     *
     * @param  array<string, mixed>  $context
     */
    private function pathFor(array $context): ?string
    {
        if (! empty($context['content_id'])) {
            return '/app/content/' . $context['content_id'];
        }

        if (! empty($context['conversation_id'])) {
            return '/app/inbox?' . http_build_query(array_filter([
                'brand' => $context['brand_id'] ?? null,
                'conversation' => $context['conversation_id'],
            ]));
        }

        return null;
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

        // Anti-SSRF: se revalida al ejecutar (el DNS pudo cambiar) y se fija la IP.
        try {
            $target = OutboundUrl::resolve($url);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $response = Http::timeout(10)
            ->withOptions(OutboundUrl::pinnedOptions($target))
            ->asJson()
            ->post($url, [
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
