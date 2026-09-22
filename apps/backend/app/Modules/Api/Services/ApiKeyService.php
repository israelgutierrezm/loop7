<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Models\User;
use App\Modules\Api\Models\ApiKey;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Genera y verifica API keys. El secreto se muestra una sola vez; en BD sólo se
 * guarda su hash SHA-256 (docs/11).
 */
class ApiKeyService
{
    /**
     * @param  list<string>  $scopes
     * @return array{model: ApiKey, plain: string}
     */
    public function generate(
        Organization $organization,
        string $name,
        array $scopes,
        ?Carbon $expiresAt = null,
        ?User $creator = null,
    ): array {
        $prefix = $this->uniquePrefix();
        $plain = $prefix . '_' . Str::random(40);

        $model = ApiKey::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'prefix' => $prefix,
            'token_hash' => self::hash($plain),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'is_active' => true,
            'created_by_user_id' => $creator?->id,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    /**
     * Resuelve una API key a partir del valor completo, verificando el hash.
     */
    public function resolve(string $plain): ?ApiKey
    {
        $prefix = substr($plain, 0, 12);
        if ($prefix === '') {
            return null;
        }

        $key = ApiKey::query()->withoutGlobalScopes()->where('prefix', $prefix)->first();
        if ($key === null || ! $key->isUsable()) {
            return null;
        }

        if (! hash_equals($key->token_hash, self::hash($plain))) {
            return null;
        }

        return $key;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function uniquePrefix(): string
    {
        do {
            $prefix = 'l7_' . Str::random(9);
        } while (ApiKey::query()->withoutGlobalScopes()->where('prefix', $prefix)->exists());

        return $prefix;
    }
}
