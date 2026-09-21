<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('brand_guidelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();

            $table->text('voice_tone')->nullable();
            $table->json('value_propositions')->nullable();
            $table->text('cta')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('preferred_vocabulary')->nullable();
            $table->json('prohibited_terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Una guía por Brand.
            $table->unique('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_guidelines');
    }
};
