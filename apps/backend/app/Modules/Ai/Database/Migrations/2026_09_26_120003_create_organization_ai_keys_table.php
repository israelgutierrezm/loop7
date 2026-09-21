<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claves propias de IA por Organization (BYOK, docs/07). Sólo disponible en
 * planes con feature.byok. Se guardan cifradas y nunca vuelven al navegador.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('organization_ai_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('provider');
            $table->text('credentials'); // cifrado at-rest
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'provider'], 'org_ai_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_keys');
    }
};
