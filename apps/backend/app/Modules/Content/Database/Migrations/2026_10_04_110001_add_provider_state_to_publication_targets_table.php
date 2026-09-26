<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progreso del proveedor entre reintentos (PublishCheckpoint): p. ej. los
 * contenedores de Instagram en proceso y el id del post ya publicado.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('publication_targets', function (Blueprint $table) {
            $table->json('provider_state')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('publication_targets', function (Blueprint $table) {
            $table->dropColumn('provider_state');
        });
    }
};
