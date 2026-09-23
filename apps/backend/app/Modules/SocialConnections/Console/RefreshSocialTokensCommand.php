<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Console;

use App\Modules\SocialConnections\Services\SocialConnectionService;
use Illuminate\Console\Command;

class RefreshSocialTokensCommand extends Command
{
    protected $signature = 'social:refresh-tokens';

    protected $description = 'Renueva los tokens sociales próximos a caducar y marca como expiradas las conexiones que ya no se pueden renovar.';

    public function handle(SocialConnectionService $connections): int
    {
        $result = $connections->refreshDue();
        $this->info("Tokens renovados: {$result['refreshed']} · Conexiones expiradas: {$result['expired']}");

        return self::SUCCESS;
    }
}
