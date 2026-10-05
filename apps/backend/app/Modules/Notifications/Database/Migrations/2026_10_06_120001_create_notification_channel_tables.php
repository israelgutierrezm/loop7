<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canales de aviso además de la app y el correo (docs/05): push del navegador y
 * WhatsApp. La configuración de cada canal la gestiona SUPERADMIN (credenciales
 * cifradas); cada usuario registra sus navegadores y su número verificado.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->text('credentials')->nullable(); // encrypted:array
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('whatsapp_phone')->nullable(); // encrypted
            $table->timestamp('whatsapp_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_phone', 'whatsapp_verified_at']);
        });
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_channels');
    }
};
