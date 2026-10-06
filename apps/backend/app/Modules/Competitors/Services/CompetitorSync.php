<?php

declare(strict_types=1);

namespace App\Modules\Competitors\Services;

use App\Modules\Brands\Models\Brand;
use App\Modules\Competitors\Data\CompetitorFetch;
use App\Modules\Competitors\Exceptions\CompetitorSourceException;
use App\Modules\Competitors\Models\Competitor;
use App\Modules\Competitors\Models\CompetitorAccount;
use App\Modules\Competitors\Models\CompetitorPost;
use App\Modules\Competitors\Models\CompetitorSnapshot;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lee una cuenta de la competencia y guarda la foto del día (una por día) y sus
 * publicaciones recientes. Un fallo queda en la cuenta, sin romper las demás.
 */
class CompetitorSync
{
    private const MAX_POSTS = 50;

    public function __construct(private readonly CompetitorSources $sources)
    {
    }

    public function sync(CompetitorAccount $account): bool
    {
        $competitor = Competitor::query()->withoutGlobalScope(OrganizationScope::class)->find($account->competitor_id);
        $brand = $competitor !== null
            ? Brand::query()->withoutGlobalScope(OrganizationScope::class)->find($competitor->brand_id)
            : null;
        $source = $this->sources->get($account->provider);
        if ($brand === null || $source === null) {
            $this->fail($account, 'Esta red ya no está disponible para seguir a la competencia.');

            return false;
        }

        try {
            $data = $source->fetch($account->handle, $this->sources->viewer($account->provider, $brand));
        } catch (CompetitorSourceException $e) {
            $this->fail($account, $e->getMessage());

            return false;
        }

        $this->store($account, $data);

        return true;
    }

    public function store(CompetitorAccount $account, CompetitorFetch $data): void
    {
        DB::transaction(function () use ($account, $data): void {
            $account->forceFill([
                'external_id' => $data->externalId !== '' ? $data->externalId : $account->external_id,
                'display_name' => $data->name,
                'avatar_url' => $data->avatarUrl,
                'profile_url' => $data->profileUrl,
                'status' => CompetitorAccount::STATUS_ACTIVE,
                'last_error' => null,
                'failures' => 0,
                'last_synced_at' => now(),
            ])->save();

            // Una foto por día (whereDate: el formato guardado varía según la base de datos).
            $snapshot = CompetitorSnapshot::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('competitor_account_id', $account->id)
                ->whereDate('date', Carbon::today())
                ->first() ?? new CompetitorSnapshot(['competitor_account_id' => $account->id, 'date' => Carbon::today()]);
            $snapshot->fill([
                'organization_id' => $account->organization_id,
                'followers' => $data->followers,
                'posts_count' => $data->postsCount,
                'weekly' => $data->weekly,
            ])->save();

            foreach (array_slice($data->posts, 0, self::MAX_POSTS) as $post) {
                CompetitorPost::query()->withoutGlobalScope(OrganizationScope::class)->updateOrCreate(
                    ['competitor_account_id' => $account->id, 'external_id' => $post->externalId],
                    [
                        'organization_id' => $account->organization_id,
                        'published_at' => $post->publishedAt,
                        'type' => $post->type,
                        'caption' => $post->caption,
                        'permalink' => $post->permalink,
                        'thumbnail_url' => $post->thumbnailUrl,
                        'likes' => $post->likes,
                        'comments' => $post->comments,
                        'views' => $post->views,
                    ],
                );
            }
        });
    }

    private function fail(CompetitorAccount $account, string $message): void
    {
        $account->forceFill([
            'status' => CompetitorAccount::STATUS_ERROR,
            'last_error' => mb_substr($message, 0, 500),
            'failures' => $account->failures + 1,
        ])->save();
    }
}
