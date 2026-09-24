<?php

declare(strict_types=1);

namespace App\Modules\Content\Events;

use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ninguna red pudo publicar el contenido (estado final FAILED). Complementa a
 * ContentPublished, que cubre published/partial.
 */
class ContentPublicationFailed
{
    use Dispatchable;

    public function __construct(public readonly ContentItem $content)
    {
    }
}
