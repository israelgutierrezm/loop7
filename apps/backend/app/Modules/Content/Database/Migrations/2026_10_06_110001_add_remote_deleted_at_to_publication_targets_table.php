<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se borró de la red una publicación hecha desde Loop7 (estado
 * `deleted` del destino). El identificador remoto se conserva para la auditoría.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('publication_targets', function (Blueprint $table): void {
            $table->timestamp('remote_deleted_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('publication_targets', function (Blueprint $table): void {
            $table->dropColumn('remote_deleted_at');
        });
    }
};
