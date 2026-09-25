<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * brand_documents nunca tuvo API ni UI (el conocimiento de marca vive en
 * brand_knowledge_items: notas, FAQs y URLs). Se retira la tabla.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('brand_documents');
    }

    public function down(): void
    {
        Schema::create('brand_documents', function (Blueprint $table): void {
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
};
