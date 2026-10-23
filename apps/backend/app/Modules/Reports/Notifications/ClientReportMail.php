<?php

declare(strict_types=1);

namespace App\Modules\Reports\Notifications;

use App\Modules\Reports\Rendering\ReportFormat;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Informe para clientes por correo, con el PDF adjunto. Se envía desde el job
 * que lo genera (no se encola aparte: el PDF no viaja por la cola). Con marca
 * blanca, el remitente es el nombre de la organización.
 */
class ClientReportMail extends Notification
{
    /**
     * @param  array<string, mixed>  $report  foto del informe (ReportDataBuilder)
     */
    public function __construct(
        private readonly array $report,
        private readonly string $pdf,
        private readonly string $filename,
        private readonly ?string $replyTo = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = ReportFormat::period($this->report['period']['from'], $this->report['period']['to']);
        $product = (string) $this->report['branding']['name'];

        $message = (new MailMessage())
            ->from((string) config('mail.from.address'), $product)
            ->subject($this->report['name'] . ' · ' . $this->report['brand']['name'] . ' · ' . $period)
            ->view(['html' => 'reports::mail', 'text' => 'reports::mail-text'], [
                'report' => $this->report,
                'period' => $period,
                'color' => ReportFormat::color($this->report['branding']['color'] ?? null),
                'f' => new ReportFormat(),
            ])
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);

        if ($this->replyTo !== null) {
            $message->replyTo($this->replyTo);
        }

        return $message;
    }
}
