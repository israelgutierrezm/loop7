<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones del inbox (comentarios/DMs/menciones) donde las APIs lo permitan
 * (docs/05). Tenant-owned; se sincronizan desde los proveedores y se dedupean por
 * (conexión, external_id).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('social_connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->foreignId('social_connection_destination_id')->nullable()
                ->constrained('social_connection_destinations')->nullOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->string('type')->default('comment'); // comment | dm | mention
            $table->string('participant_name')->nullable();
            $table->string('participant_external_id')->nullable();
            $table->string('status')->default('open'); // open | pending | resolved | snoozed
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->text('preview')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['social_connection_id', 'external_id'], 'inbox_conv_ext_unique');
            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
