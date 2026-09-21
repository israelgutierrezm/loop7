<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // stripe | mercadopago | openpay | manual
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
            $table->string('environment', 16)->default('test'); // test | production
            $table->json('config')->nullable(); // configuración NO secreta
            $table->timestamps();
        });

        Schema::create('payment_gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('environment', 16); // test | production
            $table->string('key'); // p.ej. secret_key, public_key, webhook_secret
            $table->text('value'); // cifrado a nivel de aplicación
            $table->timestamps();

            $table->unique(['payment_gateway_id', 'environment', 'key'], 'payment_gw_cred_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_credentials');
        Schema::dropIfExists('payment_gateways');
    }
};
