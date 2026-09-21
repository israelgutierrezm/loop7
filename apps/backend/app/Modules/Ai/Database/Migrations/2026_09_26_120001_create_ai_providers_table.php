<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de proveedores de IA, configurable desde SUPERADMIN (docs/07).
 * Las credenciales de plataforma se guardan cifradas (cast encrypted:array).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('config')->nullable();     // modelos, modelo por defecto, costes por operación
            $table->text('credentials')->nullable(); // cifrado at-rest
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
