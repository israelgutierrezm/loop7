<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de aviso "tu prueba termina pronto" para enviarlo una sola vez por
 * periodo de prueba (se reinicia al ampliar la prueba).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('trial_reminder_sent_at');
        });
    }
};
