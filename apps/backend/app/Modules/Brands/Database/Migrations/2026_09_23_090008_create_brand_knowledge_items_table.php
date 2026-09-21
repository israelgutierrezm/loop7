<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('brand_knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('type', 24); // faq | url | note
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('indexed_at')->nullable(); // para RAG futuro
            $table->timestamps();

            $table->index(['brand_id', 'type']);
        });

        Schema::create('brand_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_documents');
        Schema::dropIfExists('brand_knowledge_items');
    }
};
