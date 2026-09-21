<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credencial de pasarela. El valor se cifra at-rest (cast encrypted) y nunca
 * se expone completo al frontend (ver máscara en el controlador SUPERADMIN).
 *
 * @property int $id
 * @property int $payment_gateway_id
 * @property string $environment
 * @property string $key
 * @property string $value
 */
class PaymentGatewayCredential extends Model
{
    protected $fillable = ['payment_gateway_id', 'environment', 'key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public function maskedValue(): string
    {
        $value = (string) $this->value;
        $length = mb_strlen($value);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', max(4, $length - 4)) . mb_substr($value, -4);
    }
}
