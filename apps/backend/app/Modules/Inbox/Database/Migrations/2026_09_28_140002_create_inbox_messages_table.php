<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de una conversación del inbox. `type`:
 *  - inbound: mensaje entrante del participante
 *  - reply:   respuesta saliente enviada al proveedor
 *  - note:    nota interna (no se envía a la red)
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('type')->default('inbound'); // inbound | reply | note
            $table->string('author_name')->nullable();
            $table->string('author_external_id')->nullable();
            $table->text('body');
            $table->foreignId('via_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'external_id'], 'inbox_msg_ext_unique');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
