<?php

declare(strict_types=1);

namespace App\Modules\Content\Events;

use App\Models\User;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un contenido pasó a revisión: quienes pueden aprobarlo deben enterarse.
 */
class ContentSubmittedForReview
{
    use Dispatchable;

    public function __construct(
        public readonly ContentItem $content,
        public readonly User $submittedBy,
    ) {
    }
}
