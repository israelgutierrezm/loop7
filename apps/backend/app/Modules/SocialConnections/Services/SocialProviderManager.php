<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Services;

use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use App\Modules\SocialConnections\Providers\FakeSocialProvider;
use App\Modules\SocialConnections\Providers\InstagramProvider;
use Illuminate\Support\Collection;

class SocialProviderManager
{
    /**
     * Proveedores que comparten la app (y por tanto las credenciales) de otro:
     * Instagram se conecta con la misma app de Meta que Facebook.
     */
    private const SHARED_APP = ['instagram' => 'facebook'];

    /** Ajustes no secretos del catálogo que se pasan al adaptador. */
    private const ADAPTER_SETTINGS = ['graph_version'];

    /** @var array<string, SocialProviderInterface> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'fake' => new FakeSocialProvider(),
            'facebook' => new FacebookProvider(),
            'instagram' => new InstagramProvider(),
        ];
    }

    public function adapter(string $key): ?SocialProviderInterface
    {
        return $this->adapters[$key] ?? null;
    }

    /**
     * @return array<string, SocialProviderInterface>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    public function record(string $key): ?SocialProvider
    {
        return SocialProvider::query()->where('key', $key)->first();
    }

    /**
     * Credenciales (descifradas) + ajustes del proveedor para su adaptador. Si
     * comparte app con otro proveedor y no tiene credenciales propias, usa las
     * de ese proveedor (p. ej. Instagram usa las de Facebook).
     *
     * @return array<string, string>
     */
    public function credentials(string $key): array
    {
        $record = $this->record($key);
        $shared = isset(self::SHARED_APP[$key]) ? $this->record(self::SHARED_APP[$key]) : null;

        $credentials = $record?->credentialMap() ?? [];
        if ($shared !== null && empty($credentials['client_id'])) {
            $credentials = [...$shared->credentialMap(), ...$credentials];
        }

        // Ajustes: primero los del propio proveedor, después los de la app compartida.
        foreach (self::ADAPTER_SETTINGS as $setting) {
            foreach ([$record, $shared] as $source) {
                $value = $source?->config[$setting] ?? null;
                if (is_string($value) && $value !== '') {
                    $credentials[$setting] = $value;

                    break;
                }
            }
        }

        return $credentials;
    }

    /**
     * Scopes a solicitar: los configurados en SUPERADMIN o los del adaptador.
     *
     * @return list<string>
     */
    public function scopes(string $key): array
    {
        $configured = $this->record($key)?->config['scopes'] ?? null;
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        return $this->adapter($key)?->defaultScopes() ?? [];
    }

    /**
     * Proveedor cuya app reutiliza $key (o null si tiene app propia).
     */
    public function sharesAppWith(string $key): ?string
    {
        return self::SHARED_APP[$key] ?? null;
    }

    /**
     * Registros de proveedores habilitados que tienen adaptador disponible.
     *
     * @return Collection<int, SocialProvider>
     */
    public function enabled(): Collection
    {
        return SocialProvider::query()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (SocialProvider $p) => isset($this->adapters[$p->key]))
            ->values();
    }

    public function isEnabled(string $key): bool
    {
        return SocialProvider::query()->where('key', $key)->where('is_enabled', true)->exists();
    }
}
