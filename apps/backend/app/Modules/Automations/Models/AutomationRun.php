<?php

declare(strict_types=1);

namespace App\Modules\Automations\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Ejecución registrada de una automatización.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $automation_id
 * @property string $status
 * @property string $trigger
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AutomationRun extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'automation_id', 'status', 'trigger', 'message', 'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
