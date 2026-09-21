<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('post_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('body')->nullable();
            $table->string('format', 24)->default('text');
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index('content_item_id');
        });

        Schema::create('post_variant_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_variant_id')->constrained('post_variants')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['post_variant_id', 'media_asset_id'], 'post_variant_media_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variant_media');
        Schema::dropIfExists('post_variants');
    }
};
