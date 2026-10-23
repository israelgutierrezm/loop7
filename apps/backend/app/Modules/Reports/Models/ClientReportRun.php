<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Enums\RunTrigger;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un informe generado: el PDF (disco privado) y la foto de los datos con que se
 * hizo; si se envió, a quién y cuándo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property int $client_report_id
 * @property \Illuminate\Support\Carbon $period_from
 * @property \Illuminate\Support\Carbon $period_to
 * @property RunTrigger $trigger
 * @property RunStatus $status
 * @property array<string, mixed>|null $data
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $error
 * @property list<string>|null $recipients
 * @property list<string>|null $delivered_to
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property int|null $requested_by_user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ClientReport $report
 */
class ClientReportRun extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'client_report_id', 'period_from', 'period_to', 'trigger', 'status',
        'data', 'file_path', 'file_size', 'error', 'recipients', 'delivered_to', 'sent_at', 'requested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'trigger' => RunTrigger::class,
            'status' => RunStatus::class,
            'data' => 'array',
            'file_size' => 'integer',
            'recipients' => 'array',
            'delivered_to' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClientReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }
}
