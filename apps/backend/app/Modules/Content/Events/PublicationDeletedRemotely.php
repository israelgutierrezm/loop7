<?php

declare(strict_types=1);

namespace App\Modules\Content\Events;

use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PublicationTarget;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite cuando una publicación se borra de su red desde Loop7 (p. ej. para
 * avisar a integraciones por webhook sin acoplar el módulo Content a ellas).
 */
class PublicationDeletedRemotely
{
    use Dispatchable;

    public function __construct(
        public readonly ContentItem $content,
        public readonly PublicationTarget $target,
        public readonly string $provider,
    ) {
    }
}
