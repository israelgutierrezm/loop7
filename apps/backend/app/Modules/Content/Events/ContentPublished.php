<?php

declare(strict_types=1);

namespace App\Modules\Content\Events;

use App\Modules\Content\Models\ContentItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite cuando un ContentItem alcanza un estado final de publicación
 * (published / partial). Permite que otros módulos (p.ej. Automations)
 * reaccionen sin acoplar el módulo Content a ellos.
 */
class ContentPublished
{
    use Dispatchable;

    public function __construct(
        public readonly ContentItem $content,
        public readonly string $status,
    ) {
    }
}
