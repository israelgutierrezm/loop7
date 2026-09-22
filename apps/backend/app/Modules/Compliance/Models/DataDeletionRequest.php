<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una solicitud de borrado de datos (Meta u otro proveedor).
 *
 * @property int $id
 * @property string $confirmation_code
 * @property string $provider
 * @property string $external_user_id
 * @property string $status
 * @property int $deleted_items
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DataDeletionRequest extends Model
{
    protected $fillable = [
        'confirmation_code', 'provider', 'external_user_id', 'status', 'deleted_items', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_items' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
