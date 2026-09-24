<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos in-app (canal "database" de Laravel) con la Organization del aviso:
 * la campana lista los del usuario en la Organization actual.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            // Columnas de morphs() sin su índice propio: lo cubre el índice compuesto.
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Campana: no leídos del usuario en su Organization, del más reciente al más antiguo.
            $table->index(['notifiable_type', 'notifiable_id', 'organization_id', 'read_at'], 'notifications_inbox_index');
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('notifications');
    }
};
