<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('social_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();          // configuración NO secreta
            $table->text('credentials')->nullable();      // {client_id, client_secret, ...} cifrado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_providers');
    }
};
