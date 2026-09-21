<?php

declare(strict_types=1);

namespace App\Modules\Automations\Listeners;

use App\Modules\Automations\Enums\AutomationTrigger;
use App\Modules\Automations\Services\AutomationEngine;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Events\ContentPublished;

/**
 * Traduce el evento ContentPublished en el disparador de automatización
 * correspondiente, construyendo un contexto plano para las condiciones.
 */
class RunAutomationsForContentPublished
{
    public function __construct(private readonly AutomationEngine $engine)
    {
    }

    public function handle(ContentPublished $event): void
    {
        $content = $event->content;
        $brand = Brand::query()->withoutGlobalScopes()->find($content->brand_id);

        $this->engine->dispatchForTrigger(
            AutomationTrigger::CONTENT_PUBLISHED,
            $content->organization_id,
            $content->brand_id,
            [
                'content_title' => $content->title,
                'content_status' => $event->status,
                'brand' => $brand->name,
            ],
        );
    }
}
