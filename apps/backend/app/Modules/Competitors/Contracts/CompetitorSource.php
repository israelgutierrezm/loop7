<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Contracts;

use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Data\CompetitorViewer;
use App\Modules\Competitors\Exceptions\CompetitorNotFoundException;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;

/**
 * Fuente de datos públicos de cuentas de la competencia en una red. Sólo se
 * implementa donde la API oficial lo permite (docs/05): nunca scraping.
 */
interface CompetitorSource
{
    /** Clave de la red (coincide con la del proveedor social: instagram, facebook…). */
    public function key(): string;

    public function label(): string;

    /** Qué escribir para identificar la cuenta. */
    public function handleHint(): string;

    /** ¿La red da sus publicaciones recientes con interacciones? */
    public function providesPosts(): bool;

    /**
     * Normaliza lo que escribe el usuario (@usuario, URL del perfil…); null si no es válido.
     */
    public function normalizeHandle(string $input): ?string;

    /**
     * Perfil, métricas actuales y (si la red lo da) publicaciones recientes.
     *
     * @throws CompetitorNotFoundException la cuenta no existe o la red no deja leerla
     * @throws CompetitorSourceException fallo de la red, del token o de permisos de la app
     */
    public function fetch(string $handle, CompetitorViewer $viewer): CompetitorFetch;
}
