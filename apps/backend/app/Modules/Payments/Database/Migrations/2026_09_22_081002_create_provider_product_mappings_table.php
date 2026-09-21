<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('provider_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('interval', 16);
            $table->string('environment', 16);
            $table->string('provider_price_id');
            $table->timestamps();

            $table->unique(['gateway', 'plan_id', 'interval', 'environment'], 'provider_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_product_mappings');
    }
};
