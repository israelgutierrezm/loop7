<?php

declare(strict_types=1);

namespace App\Modules\Content\Jobs;

use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Content\Services\PublishingService;
use App\Support\Security\SecretRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publica un PublicationTarget en su red. Reintentable, idempotente y con
 * protección de solapamiento por target. La publicación real ocurre aquí para
 * aislar cada red (un fallo no afecta a las demás; docs/15).
 */
class PublishSocialPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Cabe la espera al procesamiento de videos de Instagram (hasta 2 min) más las
     * llamadas a la red. `retry_after` de la cola debe ser mayor (config/queue.php).
     */
    public int $timeout = 300;

    public function __construct(public readonly int $targetId)
    {
        $this->onQueue('publishing');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('publish-target-' . $this->targetId))->dontRelease(),
            new RateLimited('social-publish'),
        ];
    }

    public function handle(PublishingService $publishing): void
    {
        $target = PublicationTarget::query()->withoutGlobalScopes()->find($this->targetId);

        if ($target === null) {
            return;
        }

        $publishing->publishTarget($target, finalAttempt: $this->attempts() >= $this->tries);
    }

    public function failed(Throwable $exception): void
    {
        $target = PublicationTarget::query()->withoutGlobalScopes()->find($this->targetId);

        if ($target !== null && $target->status !== TargetStatus::PUBLISHED) {
            $target->update([
                'status' => TargetStatus::FAILED->value,
                'error' => Str::limit(SecretRedactor::redact($exception->getMessage()), 1000),
            ]);
            app(PublishingService::class)->rollup($target);
        }
    }
}
