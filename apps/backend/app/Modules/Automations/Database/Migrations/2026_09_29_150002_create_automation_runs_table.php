<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de ejecuciones de automatizaciones (para trazabilidad y depuración).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('status'); // success | failed | skipped
            $table->string('trigger');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['automation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
