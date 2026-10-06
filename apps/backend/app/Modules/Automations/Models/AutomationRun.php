<?php

declare(strict_types=1);

namespace App\Modules\Automations\Models;

use App\Modules\Automations\Enums\AutomationRunStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ejecución registrada de una automatización, con la traza de cada paso. Si
 * está en una espera, `resume_at` y `resume_step` dicen cuándo y desde dónde
 * sigue.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $automation_id
 * @property int|null $brand_id
 * @property AutomationRunStatus $status
 * @property string $trigger
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property list<array<string, mixed>>|null $steps
 * @property \Illuminate\Support\Carbon|null $resume_at
 * @property string|null $resume_step
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Automation|null $automation
 */
class AutomationRun extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'automation_id', 'brand_id', 'status', 'trigger', 'message', 'context', 'steps',
        'resume_at', 'resume_step',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'context' => 'array',
            'steps' => 'array',
            'resume_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
