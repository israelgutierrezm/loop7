<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inicio de sesión único con SAML 2.0 (docs/03): dominios de correo de cada
 * organización (verificados por DNS) y su conexión con el proveedor de
 * identidad (IdP).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('organization_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            // Copia del dominio sólo cuando está verificado: el índice único garantiza
            // en la base de datos que un dominio verificado tiene una sola dueña.
            $table->string('verified_domain', 253)->nullable()->unique();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'domain']);
        });

        Schema::create('sso_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            // Obligatorio: quien tiene un correo de los dominios verificados entra sólo por SSO.
            $table->boolean('enforced')->default(false);
            $table->string('idp_entity_id', 1024)->nullable();
            $table->text('idp_sso_url')->nullable();
            $table->text('idp_certificate')->nullable();
            $table->boolean('jit_provisioning')->default(false);
            $table->string('default_role', 64)->default('VIEWER');
            $table->string('email_attribute', 255)->nullable();
            $table->string('name_attribute', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
        Schema::dropIfExists('organization_domains');
    }
};
