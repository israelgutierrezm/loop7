<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de uso de IA por Organization (docs/07 AI Usage). No se guardan
 * prompts completos: sólo metadatos, unidades, créditos, coste y latencia.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('modality');   // text | image | video
            $table->string('operation');  // generate_post, adapt_variant, ...
            $table->unsignedInteger('units')->default(0);        // tokens / imágenes
            $table->unsignedInteger('credits')->default(0);      // créditos consumidos
            $table->unsignedInteger('cost_cents')->default(0);   // coste interno estimado
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status')->default('succeeded');      // succeeded | failed
            $table->boolean('byok')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
