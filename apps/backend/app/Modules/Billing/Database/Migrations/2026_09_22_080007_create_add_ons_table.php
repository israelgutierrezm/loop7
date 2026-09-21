<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Entitlement que incrementa y cuánto por unidad contratada.
            $table->string('entitlement_key');
            $table->integer('quantity_per_unit')->default(1);
            $table->unsignedInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('add_on_id')->constrained('add_ons')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'add_on_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_add_ons');
        Schema::dropIfExists('add_ons');
    }
};
