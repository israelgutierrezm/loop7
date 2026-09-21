<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Enums;

enum MessageType: string
{
    case INBOUND = 'inbound';
    case REPLY = 'reply';
    case NOTE = 'note';
}
