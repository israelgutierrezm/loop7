<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Endpoint de webhooks de una Organization (tenant-owned). Los secretos de
 * firma se guardan cifrados y nunca se serializan.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property string $url
 * @property string|null $description
 * @property list<string> $events
 * @property string $secret
 * @property string|null $previous_secret
 * @property \Illuminate\Support\Carbon|null $previous_secret_expires_at
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property string|null $disabled_reason
 * @property \Illuminate\Support\Carbon|null $last_delivery_at
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'url', 'description', 'events', 'secret', 'previous_secret',
        'previous_secret_expires_at', 'is_active', 'consecutive_failures', 'disabled_at',
        'disabled_reason', 'last_delivery_at', 'created_by_user_id',
    ];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivery_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * Secretos con los que se firma ahora: el vigente y, durante la ventana
     * de rotación, también el anterior.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [$this->secret];
        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * Secreto enmascarado para mostrarlo (sólo los últimos 4 caracteres).
     */
    public function maskedSecret(): string
    {
        return 'whsec_••••' . substr($this->secret, -4);
    }
}
