<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Services;

use App\Modules\SocialConnections\Contracts\SocialProviderInterface;
use App\Modules\SocialConnections\Models\SocialProvider;
use App\Modules\SocialConnections\Providers\FacebookProvider;
use App\Modules\SocialConnections\Providers\FakeSocialProvider;
use Illuminate\Support\Collection;

class SocialProviderManager
{
    /** @var array<string, SocialProviderInterface> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'fake' => new FakeSocialProvider(),
            'facebook' => new FacebookProvider(),
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
