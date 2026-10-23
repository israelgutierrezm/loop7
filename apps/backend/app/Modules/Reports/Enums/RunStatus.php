<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

enum RunStatus: string
{
    case PENDING = 'pending';
    case READY = 'ready';
    case FAILED = 'failed';
}
