<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;
use App\Modules\Competitors\Models\Competitor;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Casos de uso de la competencia: alta de competidores y de sus cuentas (cada
 * cuenta se busca en la red antes de guardarla: así el error sale al momento),
 * con el límite del plan y auditoría.
 */
final class CompetitorService
{
    public const MAX_ACCOUNTS_PER_COMPETITOR = 6;

    public function __construct(
        private readonly CompetitorSources $sources,
        private readonly CompetitorSync $sync,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  list<array{provider: string, handle: string}>  $accounts
     *
     * @throws ValidationException|PlanLimitExceededException
     */
    public function create(Brand $brand, string $name, array $accounts, User $user): Competitor
    {
        $found = $this->lookup($brand, $accounts, 'accounts');

        $competitor = DB::transaction(function () use ($brand, $name, $found, $user): Competitor {
            $competitor = Competitor::query()->create([
                'organization_id' => $brand->organization_id,
                'brand_id' => $brand->id,
                'name' => $name,
                'created_by_user_id' => $user->id,
            ]);
            foreach ($found as [$provider, $data]) {
                $this->persist($competitor, $provider, $data);
            }

            return $competitor;
        });

        $this->audit->log(AuditAction::COMPETITOR_CREATED, $competitor, $this->summary($competitor));

        return $competitor;
    }

    /**
     * @throws ValidationException|PlanLimitExceededException
     */
    public function addAccount(Competitor $competitor, string $provider, string $handle): CompetitorAccount
    {
        if ($competitor->accounts()->count() >= self::MAX_ACCOUNTS_PER_COMPETITOR) {
            throw ValidationException::withMessages(['handle' => 'Cada competidor admite hasta ' . self::MAX_ACCOUNTS_PER_COMPETITOR . ' cuentas.']);
        }

        $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($competitor->brand_id);
        [[, $data]] = $this->lookup($brand, [['provider' => $provider, 'handle' => $handle]], null, $competitor);

        $account = DB::transaction(fn (): CompetitorAccount => $this->persist($competitor, $provider, $data));

        $this->audit->log(AuditAction::COMPETITOR_ACCOUNT_ADDED, $competitor, [
            'name' => $competitor->name,
            'account' => "{$provider}:{$account->handle}",
        ]);

        return $account;
    }

    public function removeAccount(CompetitorAccount $account): void
    {
        $competitor = $account->competitor;
        $label = "{$account->provider}:{$account->handle}";
        $account->delete();

        $this->audit->log(AuditAction::COMPETITOR_ACCOUNT_REMOVED, $competitor, [
            'name' => $competitor?->name,
            'account' => $label,
        ]);
    }

    public function rename(Competitor $competitor, string $name): Competitor
    {
        $previous = $competitor->name;
        $competitor->update(['name' => $name]);

        if ($previous !== $name) {
            $this->audit->log(AuditAction::COMPETITOR_UPDATED, $competitor, ['name' => $name, 'previous' => $previous]);
        }

        return $competitor;
    }

    public function delete(Competitor $competitor): void
    {
        $summary = $this->summary($competitor);
        $competitor->delete(); // en cascada: cuentas, fotos y publicaciones

        $this->audit->log(AuditAction::COMPETITOR_DELETED, null, $summary);
    }

    /**
     * Cuentas seguidas en la organización y límite del plan (-1 = ilimitado).
     *
     * @return array{used: int, limit: int}
     */
    public function usage(Organization $organization): array
    {
        return [
            'used' => CompetitorAccount::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->count(),
            'limit' => $this->entitlements->limit($organization, Entitlement::COMPETITOR_ACCOUNTS_MAX),
        ];
    }

    /**
     * Busca cada cuenta en su red. Los errores salen juntos, por cuenta.
     *
     * @param  list<array{provider: string, handle: string}>  $accounts
     * @param  string|null  $prefix  `accounts` → errores en `accounts.N.handle`; null → en `handle`
     * @return list<array{0: string, 1: CompetitorFetch}>
     */
    private function lookup(Brand $brand, array $accounts, ?string $prefix, ?Competitor $competitor = null): array
    {
        $organization = Organization::query()->findOrFail($brand->organization_id);
        $this->ensureCapacity($organization, count($accounts));

        $found = [];
        $errors = [];
        $seen = [];
        foreach ($accounts as $i => $account) {
            $field = fn (string $name): string => $prefix !== null ? "{$prefix}.{$i}.{$name}" : $name;
            $source = $this->sources->get($account['provider']);
            if ($source === null) {
                $errors[$field('provider')] = 'Elige una red.';

                continue;
            }

            $handle = $source->normalizeHandle($account['handle']);
            if ($handle === null) {
                $errors[$field('handle')] = "Escribe el {$source->handleHint()}.";

                continue;
            }
            if (isset($seen[$source->key() . ':' . $handle])
                || ($competitor !== null && $competitor->accounts()->where('provider', $source->key())->where('handle', $handle)->exists())) {
                $errors[$field('handle')] = 'Ya sigues esa cuenta.';

                continue;
            }
            $seen[$source->key() . ':' . $handle] = true;

            try {
                $found[] = [$source->key(), $source->fetch($handle, $this->sources->viewer($source->key(), $brand))];
            } catch (CompetitorSourceException $e) {
                $errors[$field('handle')] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $found;
    }

    private function ensureCapacity(Organization $organization, int $adding): void
    {
        ['used' => $used, 'limit' => $limit] = $this->usage($organization);

        if ($limit !== Entitlement::UNLIMITED && $used + $adding > $limit) {
            throw new PlanLimitExceededException(
                $limit <= 0
                    ? 'Tu plan no incluye el análisis de competidores.'
                    : "Tu plan permite seguir {$limit} cuentas de la competencia (ya sigues {$used}).",
                Entitlement::COMPETITOR_ACCOUNTS_MAX,
            );
        }
    }

    private function persist(Competitor $competitor, string $provider, CompetitorFetch $data): CompetitorAccount
    {
        $account = CompetitorAccount::query()->create([
            'organization_id' => $competitor->organization_id,
            'competitor_id' => $competitor->id,
            'provider' => $provider,
            'handle' => $data->handle,
        ]);
        $this->sync->store($account, $data);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Competitor $competitor): array
    {
        return [
            'name' => $competitor->name,
            'accounts' => $competitor->accounts()->get()->map(fn (CompetitorAccount $a) => "{$a->provider}:{$a->handle}")->all(),
        ];
    }
}
