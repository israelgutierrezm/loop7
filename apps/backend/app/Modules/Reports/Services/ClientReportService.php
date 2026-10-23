<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Reports\Enums\ReportFrequency;
use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Enums\RunTrigger;
use App\Modules\Reports\Jobs\GenerateClientReport;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\Reports\Models\ClientReportRun;
use App\Support\Tenancy\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Informes para clientes de una marca (docs/05): alta, cambios y baja con el
 * límite del plan (client_reports.max) y auditoría; y la petición de generarlos.
 */
final class ClientReportService
{
    public const MAX_RECIPIENTS = 10;

    /** Máximo por marca aunque el plan sea ilimitado (cada uno puede enviar correos). */
    public const MAX_PER_BRAND = 25;

    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{used: int, limit: int}
     */
    public function usage(Organization $organization): array
    {
        return [
            'used' => ClientReport::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->count(),
            'limit' => $this->entitlements->limit($organization, Entitlement::CLIENT_REPORTS_MAX),
        ];
    }

    public function included(Organization $organization): bool
    {
        return $this->entitlements->limit($organization, Entitlement::CLIENT_REPORTS_MAX) !== 0;
    }

    /**
     * @throws PlanLimitExceededException
     */
    public function ensureIncluded(Organization $organization): void
    {
        if (! $this->included($organization)) {
            throw new PlanLimitExceededException('Tu plan no incluye informes para clientes.', Entitlement::CLIENT_REPORTS_MAX);
        }
    }

    /**
     * @param  array{name: string, period: string, sections: list<string>, intro: string|null, recipients: list<string>, frequency: string|null, send_day: int|null, send_hour: int}  $data
     */
    public function create(Organization $organization, Brand $brand, array $data, User $user): ClientReport
    {
        $this->ensureIncluded($organization);
        $usage = $this->usage($organization);
        if ($usage['limit'] !== Entitlement::UNLIMITED && $usage['used'] >= $usage['limit']) {
            throw new PlanLimitExceededException('Has alcanzado el número de informes incluidos en tu plan.', Entitlement::CLIENT_REPORTS_MAX);
        }
        $perBrand = ClientReport::query()->withoutGlobalScope(OrganizationScope::class)->where('brand_id', $brand->id)->count();
        if ($perBrand >= self::MAX_PER_BRAND) {
            throw ValidationException::withMessages(['name' => 'Una marca puede tener hasta ' . self::MAX_PER_BRAND . ' informes.']);
        }

        $report = new ClientReport([
            ...$data,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'created_by_user_id' => $user->id,
        ]);
        $report->next_send_at = $this->nextSend($report, $brand);
        $report->save();

        $this->audit->log(AuditAction::REPORT_CREATED, $report, $this->auditProperties($report), organizationId: $organization->id);

        return $report;
    }

    /**
     * @param  array{name: string, period: string, sections: list<string>, intro: string|null, recipients: list<string>, frequency: string|null, send_day: int|null, send_hour: int}  $data
     */
    public function update(ClientReport $report, Brand $brand, array $data): ClientReport
    {
        $report->fill($data);
        $changed = array_keys($report->getDirty());
        if (array_intersect($changed, ['frequency', 'send_day', 'send_hour']) !== [] || ($report->frequency !== null && $report->next_send_at === null)) {
            $report->next_send_at = $this->nextSend($report, $brand);
        }
        $report->save();

        if ($changed !== []) {
            $this->audit->log(AuditAction::REPORT_UPDATED, $report, [
                'changes' => $changed,
                ...$this->auditProperties($report),
            ], organizationId: $report->organization_id);
        }

        return $report;
    }

    public function delete(ClientReport $report): void
    {
        $files = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('client_report_id', $report->id)
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->all();
        $properties = ['name' => $report->name, 'brand_id' => $report->brand_id];
        $organizationId = $report->organization_id;

        DB::transaction(fn () => $report->delete());
        Storage::disk(ReportGenerator::DISK)->delete($files);

        $this->audit->log(AuditAction::REPORT_DELETED, null, $properties, organizationId: $organizationId);
    }

    /**
     * Pide generar el informe del periodo (y enviarlo si el disparador envía).
     */
    public function requestRun(ClientReport $report, RunTrigger $trigger, CarbonImmutable $from, CarbonImmutable $to, ?User $user): ClientReportRun
    {
        if ($trigger->sends() && $report->recipients === []) {
            throw ValidationException::withMessages(['recipients' => 'Añade al menos un destinatario para enviar el informe.']);
        }
        $busy = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('client_report_id', $report->id)
            ->where('status', RunStatus::PENDING->value)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();
        if ($busy) {
            throw ValidationException::withMessages(['report' => 'Este informe ya se está generando. Espera a que termine.']);
        }

        $run = ClientReportRun::query()->create([
            'organization_id' => $report->organization_id,
            'brand_id' => $report->brand_id,
            'client_report_id' => $report->id,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'trigger' => $trigger,
            'status' => RunStatus::PENDING,
            'recipients' => $trigger->sends() ? $report->recipients : null,
            'requested_by_user_id' => $user?->id,
        ]);

        GenerateClientReport::dispatch($run->id);

        return $run->refresh();
    }

    public function nextSend(ClientReport $report, Brand $brand): ?CarbonImmutable
    {
        return ReportSchedule::next(
            $report->frequency instanceof ReportFrequency ? $report->frequency : null,
            $report->send_day,
            $report->send_hour,
            $brand->timezone ?: 'UTC',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditProperties(ClientReport $report): array
    {
        return [
            'name' => $report->name,
            'period' => $report->period->value,
            'sections' => $report->sections,
            'recipients' => $report->recipients,
            'frequency' => $report->frequency?->value,
        ];
    }
}
