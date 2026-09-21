<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
        });

        Schema::create('media_asset_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('media_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['media_asset_id', 'media_tag_id'], 'media_asset_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_tags');
        Schema::dropIfExists('media_tags');
    }
};
