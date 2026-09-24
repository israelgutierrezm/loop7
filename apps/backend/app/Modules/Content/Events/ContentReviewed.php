<?php

declare(strict_types=1);

namespace App\Modules\Content\Events;

use App\Models\User;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se resolvió la revisión de un contenido: aprobado o con cambios solicitados.
 */
class ContentReviewed
{
    use Dispatchable;

    public function __construct(
        public readonly ContentItem $content,
        public readonly User $reviewer,
        public readonly bool $approved,
        public readonly ?string $note = null,
        public readonly ?int $submittedByUserId = null,
    ) {
    }
}
