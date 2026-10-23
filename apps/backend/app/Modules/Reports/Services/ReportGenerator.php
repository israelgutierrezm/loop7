<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\OrganizationBranding;
use App\Modules\Reports\Contracts\ReportRenderer;
use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Enums\RunTrigger;
use App\Modules\Reports\Models\ClientReport;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Notifications\ClientReportMail;
use App\Support\Tenancy\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Genera el PDF de un informe y, si toca, lo envía a sus destinatarios.
 * Idempotente: un reintento no rehace el PDF ni repite los correos ya enviados.
 */
final class ReportGenerator
{
    public const DISK = 'local';

    /** El logo de marca blanca se reduce a este ancho: basta para la cabecera. */
    private const LOGO_MAX_WIDTH = 600;

    public function __construct(
        private readonly ReportDataBuilder $builder,
        private readonly ReportRenderer $renderer,
        private readonly OrganizationBranding $branding,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(ClientReportRun $run): void
    {
        $report = ClientReport::query()->withoutGlobalScope(OrganizationScope::class)->find($run->client_report_id);
        $organization = Organization::query()->find($run->organization_id);
        $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $run->organization_id)
            ->find($run->brand_id);
        if ($report === null || $organization === null || $brand === null) {
            $this->fail($run, 'El informe, la marca o la organización ya no existen.');

            return;
        }

        if ($run->status === RunStatus::PENDING) {
            try {
                $timezone = $brand->timezone ?: 'UTC';
                $data = $this->builder->build(
                    $report,
                    $brand,
                    $organization,
                    CarbonImmutable::parse($run->period_from->toDateString(), $timezone),
                    CarbonImmutable::parse($run->period_to->toDateString(), $timezone),
                );
                $pdf = $this->renderer->render($data, $this->logo($organization));
                $path = "reports/{$organization->public_id}/{$run->public_id}.pdf";
                Storage::disk(self::DISK)->put($path, $pdf);
            } catch (Throwable $e) {
                report($e);
                $this->fail($run, 'No se pudo generar el informe.');

                return;
            }

            $run->forceFill([
                'status' => RunStatus::READY,
                'data' => $data,
                'file_path' => $path,
                'file_size' => strlen($pdf),
                'error' => null,
            ])->save();

            if ($run->trigger === RunTrigger::MANUAL) {
                $this->audit->log(AuditAction::REPORT_GENERATED, $run, [
                    'report' => $report->public_id,
                    'period' => [$run->period_from->toDateString(), $run->period_to->toDateString()],
                ], actor: $this->user($run->requested_by_user_id), organizationId: $organization->id);
            }
        }

        if ($run->status === RunStatus::READY && $run->trigger->sends() && $run->sent_at === null) {
            $this->deliver($run, $report, $organization);
        }
    }

    public function filename(ClientReportRun $run, ?Brand $brand = null): string
    {
        $slug = $brand->slug ?? 'marca';

        return "informe-{$slug}-{$run->period_from->toDateString()}-{$run->period_to->toDateString()}.pdf";
    }

    /**
     * Marca el informe como fallido (sin detalles internos: sólo un mensaje).
     */
    public function fail(ClientReportRun $run, string $message): void
    {
        $values = ['error' => $message];
        if ($run->status === RunStatus::PENDING) {
            $values['status'] = RunStatus::FAILED;
        }
        $run->forceFill($values)->save();

        $this->audit->log(AuditAction::REPORT_FAILED, $run, [
            'report' => ClientReport::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($run->client_report_id)->value('public_id'),
            'trigger' => $run->trigger->value,
            'error' => $message,
        ], organizationId: $run->organization_id);
    }

    private function deliver(ClientReportRun $run, ClientReport $report, Organization $organization): void
    {
        $pdf = (string) Storage::disk(self::DISK)->get((string) $run->file_path);
        $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)->find($run->brand_id);
        $filename = $this->filename($run, $brand);
        $replyTo = $this->replyTo($report, $organization);
        $delivered = $run->delivered_to ?? [];

        foreach ($run->recipients ?? [] as $email) {
            if (in_array($email, $delivered, true)) {
                continue;
            }
            // Un correo por destinatario: nadie ve las direcciones de los demás.
            Notification::route('mail', $email)->notify(new ClientReportMail((array) $run->data, $pdf, $filename, $replyTo));
            $delivered[] = $email;
            $run->forceFill(['delivered_to' => $delivered])->save();
        }

        $run->forceFill(['sent_at' => now(), 'error' => null])->save();
        $report->forceFill(['last_sent_at' => now()])->save();

        $this->audit->log(AuditAction::REPORT_SENT, $run, [
            'report' => $report->public_id,
            'trigger' => $run->trigger->value,
            'recipients' => $delivered,
            'period' => [$run->period_from->toDateString(), $run->period_to->toDateString()],
        ], actor: $this->user($run->requested_by_user_id), organizationId: $organization->id);
    }

    /**
     * Logo de marca blanca como PNG en data URI (dompdf no carga nada remoto).
     */
    private function logo(Organization $organization): ?string
    {
        $path = $this->branding->active($organization) !== null ? $this->branding->logoPath($organization) : null;
        if ($path === null || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $disk = Storage::disk(OrganizationBranding::DISK);
        if (! $disk->exists($path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) $disk->get($path));
        if ($image === false) {
            return null;
        }
        if (imagesx($image) > self::LOGO_MAX_WIDTH) {
            $scaled = imagescale($image, self::LOGO_MAX_WIDTH);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Las respuestas del cliente van a quien creó el informe (si sigue en la
     * organización) o al correo de facturación.
     */
    private function replyTo(ClientReport $report, Organization $organization): ?string
    {
        if ($report->created_by_user_id !== null) {
            $creator = $organization->users()
                ->where('users.id', $report->created_by_user_id)
                ->wherePivot('status', MembershipStatus::ACTIVE->value)
                ->first();
            if ($creator !== null && ! $creator->isBlocked()) {
                return $creator->email;
            }
        }

        $billing = $organization->billing_email ?? null;

        return is_string($billing) && $billing !== '' ? $billing : null;
    }

    private function user(?int $id): ?User
    {
        return $id !== null ? User::query()->find($id) : null;
    }
}
