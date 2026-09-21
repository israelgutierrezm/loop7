<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('brand_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('price')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();

            $table->index('brand_id');
        });

        Schema::create('brand_services', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();

            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_services');
        Schema::dropIfExists('brand_products');
    }
};
