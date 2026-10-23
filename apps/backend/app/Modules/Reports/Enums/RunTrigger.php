<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * Cómo se pidió un informe generado.
 */
enum RunTrigger: string
{
    /** Sólo el PDF, para descargarlo. */
    case MANUAL = 'manual';
    /** PDF y correo a los destinatarios en el acto. */
    case SEND = 'send';
    /** Envío programado. */
    case SCHEDULED = 'scheduled';

    public function sends(): bool
    {
        return $this !== self::MANUAL;
    }
}
