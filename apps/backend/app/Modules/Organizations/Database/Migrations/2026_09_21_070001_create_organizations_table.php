<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();

            // OWNER de la Organization. Nullable a nivel DB para permitir borrado seguro
            // de usuario; la regla de negocio exige transferir propiedad antes.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 32)->default('active')->index();

            // Datos fiscales / comerciales.
            $table->string('billing_email')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 8)->default('es');

            $table->json('settings')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
