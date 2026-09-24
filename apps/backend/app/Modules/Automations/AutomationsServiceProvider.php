<?php

declare(strict_types=1);

namespace App\Modules\Automations;

use App\Modules\Automations\Listeners\DisableAutomationsOfDeletedBrand;
use App\Modules\Automations\Listeners\RunAutomationsForContentPublished;
use App\Modules\Automations\Listeners\RunAutomationsForInboxMessage;
use App\Modules\Brands\Events\BrandDeleted;
use App\Modules\Content\Events\ContentPublished;
use App\Modules\Inbox\Events\InboxMessageReceived;
use App\Support\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class AutomationsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Escucha eventos internos de otros módulos y dispara las reglas que
        // coincidan, sin acoplar esos módulos a Automations (docs/05).
        Event::listen(ContentPublished::class, RunAutomationsForContentPublished::class);
        Event::listen(InboxMessageReceived::class, RunAutomationsForInboxMessage::class);
        Event::listen(BrandDeleted::class, DisableAutomationsOfDeletedBrand::class);
    }
}
