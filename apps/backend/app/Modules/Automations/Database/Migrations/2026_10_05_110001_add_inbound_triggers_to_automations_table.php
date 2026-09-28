<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disparadores externos de automatizaciones (docs/05): webhook entrante (URL
 * con token secreto) y feed RSS/Atom sondeado periódicamente.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table): void {
            // Configuración del disparador (p. ej. {"feed_url": "…"}).
            $table->json('trigger_config')->nullable()->after('trigger');
            // Token del webhook entrante: cifrado para mostrar la URL a quien
            // edita y su hash SHA-256 para buscarlo sin descifrar.
            $table->text('inbound_token')->nullable()->after('trigger_config');
            $table->string('inbound_token_hash', 64)->nullable()->unique()->after('inbound_token');
            // Estado del sondeo RSS (entradas vistas, último error…).
            $table->json('state')->nullable()->after('actions');
            $table->timestamp('polled_at')->nullable()->after('state');

            $table->index(['trigger', 'is_enabled', 'polled_at'], 'automations_polling_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table): void {
            $table->dropIndex('automations_polling_idx');
            $table->dropUnique(['inbound_token_hash']);
            $table->dropColumn(['trigger_config', 'inbound_token', 'inbound_token_hash', 'state', 'polled_at']);
        });
    }
};
