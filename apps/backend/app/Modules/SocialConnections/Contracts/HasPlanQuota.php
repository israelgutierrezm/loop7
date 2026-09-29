<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

/**
 * Red con un cupo que paga o comparte la plataforma (X cobra cada
 * publicación; YouTube da 100 subidas al día por proyecto para todos los
 * clientes): cada organización tiene su propio límite en el plan, que se
 * comprueba al programar y al publicar. `label` completa la frase «tu plan
 * permite N …».
 */
interface HasPlanQuota
{
    /**
     * @return array{entitlement: string, period: 'day'|'month', label: string}
     */
    public function planQuota(): array;
}
