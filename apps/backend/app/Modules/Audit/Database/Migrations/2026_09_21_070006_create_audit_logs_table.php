<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Contexto: puede ser null en eventos de plataforma (SUPERADMIN).
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            // Actor. Null cuando la acción la ejecuta el sistema.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action')->index();
            $table->nullableMorphs('auditable'); // recurso afectado (auditable_type + auditable_id)
            $table->string('description')->nullable();
            $table->json('properties')->nullable(); // before/after, contexto adicional (sin secretos)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Auditoría inmutable: sólo created_at, nunca se actualiza ni se borra.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
