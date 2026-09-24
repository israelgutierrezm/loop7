<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de plataforma editables por SUPERADMIN (datos legales, registro,
 * reglas de billing, aviso del sistema). Clave → valor JSON; los valores por
 * defecto viven en código (PlatformSettings::DEFAULTS).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
