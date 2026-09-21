<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Añade un identificador público ULID a un modelo con PK entera.
 *
 * Motivo de seguridad: nunca exponer IDs secuenciales en la API (anti-IDOR /
 * enumeración). El PK entero se mantiene interno para FKs eficientes.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getPublicIdColumn()})) {
                $model->{$model->getPublicIdColumn()} = (string) Str::ulid();
            }
        });
    }

    public function getPublicIdColumn(): string
    {
        return 'public_id';
    }

    public function getRouteKeyName(): string
    {
        return $this->getPublicIdColumn();
    }
}
