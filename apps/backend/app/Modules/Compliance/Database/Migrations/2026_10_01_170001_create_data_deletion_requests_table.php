<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de borrado de datos (requisito de Meta / App Review, docs/19).
 * No es tenant-owned: se indexa por el id de usuario externo del proveedor y
 * puede abarcar varias Organizations.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('confirmation_code', 32)->unique();
            $table->string('provider');
            $table->string('external_user_id');
            $table->string('status')->default('pending'); // pending | completed
            $table->unsignedInteger('deleted_items')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
