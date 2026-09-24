<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Services;

use App\Modules\PlatformAdmin\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Ajustes de plataforma gestionados desde SUPERADMIN → Configuración. Los
 * valores guardados se combinan con los valores por defecto y se cachean.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /**
     * Catálogo de ajustes y su valor por defecto (el tipo del defecto es el tipo esperado).
     *
     * @var array<string, bool|int|string>
     */
    public const DEFAULTS = [
        // Datos de la empresa (páginas legales, facturas, correos).
        'company.name' => 'Loop7',
        'company.legal_name' => '',
        'company.tax_id' => '',
        'company.contact_email' => '',
        'company.support_email' => '',
        'company.country' => '',
        'company.address' => '',

        // Alta de nuevas organizaciones desde /registro.
        'registration.open' => true,

        // Reglas de billing.
        'billing.trial_plan' => 'growth',
        'billing.trial_days' => 14,
        'billing.grace_days' => 7,

        // Aviso visible para todos los usuarios del panel.
        'announcement.enabled' => false,
        'announcement.message' => '',
        'announcement.tone' => 'info',
    ];

    /**
     * @return array<string, bool|int|string>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()
            ->pluck('value', 'key')
            ->all());

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = array_key_exists($key, $stored) ? $this->cast($stored[$key], $default) : $default;
        }

        return $values;
    }

    public function get(string $key): bool|int|string
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * Guarda los ajustes indicados (se ignoran claves desconocidas).
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            PlatformSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->cast($value, self::DEFAULTS[$key])],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Datos públicos (sin sesión): páginas legales, registro y aviso del sistema.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $s = $this->all();

        return [
            'company' => [
                'name' => $s['company.name'],
                'legal_name' => $s['company.legal_name'] !== '' ? $s['company.legal_name'] : $s['company.name'],
                'tax_id' => $s['company.tax_id'],
                'contact_email' => $s['company.contact_email'],
                'support_email' => $s['company.support_email'] !== '' ? $s['company.support_email'] : $s['company.contact_email'],
                'country' => $s['company.country'],
                'address' => $s['company.address'],
            ],
            'registration_open' => $s['registration.open'],
            'announcement' => $s['announcement.enabled'] && $s['announcement.message'] !== ''
                ? ['message' => $s['announcement.message'], 'tone' => $s['announcement.tone']]
                : null,
        ];
    }

    private function cast(mixed $value, bool|int|string $default): bool|int|string
    {
        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $value,
            default => is_scalar($value) ? trim((string) $value) : '',
        };
    }
}
