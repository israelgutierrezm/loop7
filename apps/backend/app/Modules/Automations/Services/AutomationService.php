<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Models\Automation;
use Illuminate\Support\Str;

/**
 * Casos de uso de las automatizaciones: alta, edición, baja y URL del webhook
 * entrante, con auditoría (sin secretos: ni el token ni la URL completa).
 */
final class AutomationService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  datos ya validados
     */
    public function create(array $data, User $user): Automation
    {
        $automation = new Automation([...$data, 'created_by_user_id' => $user->id]);
        $this->prepareTrigger($automation);
        $automation->save();

        $this->audit->log(AuditAction::AUTOMATION_CREATED, $automation, $this->summary($automation));

        return $automation;
    }

    /**
     * @param  array<string, mixed>  $data  datos ya validados
     */
    public function update(Automation $automation, array $data): Automation
    {
        $previousFeed = $automation->feedUrl();
        $automation->fill($data);

        // Otro feed (u otro disparador): se empieza de cero, sin disparar por lo que ya había.
        if ($automation->trigger !== AutomationTrigger::RSS_ITEM_PUBLISHED || $automation->feedUrl() !== $previousFeed) {
            $automation->forceFill(['state' => null, 'polled_at' => null]);
        }
        $this->prepareTrigger($automation);

        $changes = array_values(array_intersect(
            array_keys($automation->getDirty()),
            ['name', 'is_enabled', 'trigger', 'trigger_config', 'brand_id', 'conditions', 'actions'],
        ));
        $automation->save();

        if ($changes !== []) {
            $this->audit->log(AuditAction::AUTOMATION_UPDATED, $automation, [...$this->summary($automation), 'changes' => $changes]);
        }

        return $automation;
    }

    public function delete(Automation $automation): void
    {
        $summary = $this->summary($automation);
        $automation->delete();

        $this->audit->log(AuditAction::AUTOMATION_DELETED, null, $summary);
    }

    /**
     * Nueva URL del webhook entrante; la anterior deja de funcionar al momento.
     */
    public function rotateInboundToken(Automation $automation): Automation
    {
        $this->assignToken($automation);
        $automation->save();

        $this->audit->log(AuditAction::AUTOMATION_INBOUND_URL_ROTATED, $automation, ['name' => $automation->name]);

        return $automation;
    }

    /**
     * El webhook entrante necesita su token; los demás disparadores no lo conservan.
     */
    private function prepareTrigger(Automation $automation): void
    {
        if ($automation->trigger === AutomationTrigger::WEBHOOK_RECEIVED) {
            if ($automation->inbound_token_hash === null) {
                $this->assignToken($automation);
            }
        } elseif ($automation->inbound_token_hash !== null) {
            $automation->forceFill(['inbound_token' => null, 'inbound_token_hash' => null]);
        }

        if ($automation->trigger !== AutomationTrigger::RSS_ITEM_PUBLISHED) {
            $automation->trigger_config = null;
        }
    }

    private function assignToken(Automation $automation): void
    {
        $token = Str::random(48);
        $automation->forceFill(['inbound_token' => $token, 'inbound_token_hash' => hash('sha256', $token)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Automation $automation): array
    {
        return array_filter([
            'name' => $automation->name,
            'trigger' => $automation->trigger->value,
            'actions' => array_map(fn (array $a) => $a['type'], $automation->actions),
            'feed_host' => ($url = $automation->feedUrl()) !== null ? parse_url($url, PHP_URL_HOST) : null,
        ], fn ($v) => $v !== null);
    }
}
