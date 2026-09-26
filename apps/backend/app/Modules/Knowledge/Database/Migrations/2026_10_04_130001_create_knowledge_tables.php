<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos del Brand Brain y sus fragmentos indexados para la búsqueda de la
 * IA (RAG, docs/07). Siempre acotados por Organization y Brand.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('original_name');
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('mime_type', 150);
            $table->string('extension', 10);
            $table->unsignedInteger('size_bytes');
            $table->string('status', 20)->default('pending');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->unsignedInteger('characters')->default(0);
            $table->boolean('truncated')->default(false);
            $table->string('embedding_space', 120)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'brand_id']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('content');
            $table->binary('embedding')->nullable(); // float32 empaquetados
            $table->string('embedding_space', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('brand_id');
            $table->index(['knowledge_document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
    }
};
