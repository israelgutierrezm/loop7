<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Exceptions\FeedException;
use App\Modules\Automations\Models\Automation;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * Sondeo de feeds RSS/Atom: cada entrada nueva dispara la automatización. La
 * primera lectura sólo memoriza lo que ya había (no inunda de avisos al crear
 * la regla) y, si aparecen muchas de golpe, sólo cuentan las más recientes.
 */
final class RssFeedPoller
{
    public const POLL_MINUTES = 15;

    /** Con errores seguidos se espera más entre intentos. */
    private const ERROR_BACKOFF_AFTER = 4;

    private const ERROR_POLL_MINUTES = 60;

    private const MAX_SEEN = 300;

    private const MAX_NEW_PER_POLL = 5;

    public function __construct(
        private readonly FeedReader $reader,
        private readonly AutomationEngine $engine,
    ) {
    }

    /**
     * Automatizaciones RSS activas que toca revisar (las más atrasadas primero).
     *
     * @return Collection<int, Automation>
     */
    public function due(int $limit = 200): Collection
    {
        return Automation::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('trigger', AutomationTrigger::RSS_ITEM_PUBLISHED->value)
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('polled_at')->orWhere('polled_at', '<=', now()->subMinutes(self::POLL_MINUTES)))
            ->orderBy('polled_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Automation $a): bool => $this->backoffElapsed($a))
            ->values();
    }

    /**
     * Lee el feed y dispara la regla por cada entrada nueva. Devuelve cuántas.
     */
    public function poll(Automation $automation): int
    {
        $state = $automation->state ?? [];
        $url = $automation->feedUrl();
        if ($url === null) {
            $this->save($automation, [...$state, 'last_error' => 'Falta la URL del feed.']);

            return 0;
        }

        try {
            $result = $this->reader->fetch($url, $state['etag'] ?? null, $state['last_modified'] ?? null);
        } catch (FeedException $e) {
            $this->save($automation, [
                ...$state,
                'last_error' => $e->getMessage(),
                'errors' => (int) ($state['errors'] ?? 0) + 1,
            ]);

            return 0;
        }

        $state = [...$state, 'last_error' => null, 'errors' => 0];
        if ($result->notModified) {
            $this->save($automation, $state);

            return 0;
        }

        $state['etag'] = $result->etag;
        $state['last_modified'] = $result->lastModified;
        $state['feed_title'] = $result->title;

        $ids = array_map(fn (array $item): string => sha1($item['id']), $result->items);
        $seen = $state['seen'] ?? null;

        // Primera lectura: se memoriza lo existente sin disparar nada.
        if (! is_array($seen)) {
            $state['seen'] = array_slice(array_values(array_unique($ids)), 0, self::MAX_SEEN);
            $this->save($automation, $state);

            return 0;
        }

        $known = array_flip($seen);
        $new = [];
        foreach ($result->items as $position => $item) {
            if (! isset($known[sha1($item['id'])])) {
                $new[] = ['position' => $position] + $item;
            }
        }

        // De la más antigua a la más reciente (fecha; si falta, el orden del feed).
        usort($new, fn (array $a, array $b): int => [$a['published_at'] ?? '', -$a['position']] <=> [$b['published_at'] ?? '', -$b['position']]);
        $fire = array_slice($new, -self::MAX_NEW_PER_POLL);

        $state['seen'] = array_slice(array_values(array_unique([...$ids, ...$seen])), 0, self::MAX_SEEN);
        $this->save($automation, $state);

        foreach ($fire as $item) {
            $this->engine->dispatchDirect($automation, [
                'title' => $item['title'],
                'link' => $item['link'] ?? '',
                'summary' => $item['summary'],
                'author' => $item['author'] ?? '',
                'published_at' => $item['published_at'] ?? '',
                'image' => $item['image'] ?? '',
                'feed_title' => $result->title,
            ]);
        }

        return count($fire);
    }

    private function backoffElapsed(Automation $automation): bool
    {
        if ((int) ($automation->state['errors'] ?? 0) < self::ERROR_BACKOFF_AFTER || $automation->polled_at === null) {
            return true;
        }

        return $automation->polled_at->lte(now()->subMinutes(self::ERROR_POLL_MINUTES));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(Automation $automation, array $state): void
    {
        $state['last_polled_at'] = now()->toIso8601String();
        $automation->forceFill(['state' => $state, 'polled_at' => now()])->save();
    }
}
