<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de automatización (trigger + condiciones + acciones), docs/05.
 * Tenant-owned; pueden ser globales de la Organization o acotadas a una Brand.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->string('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'trigger', 'is_enabled'], 'automations_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
