<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Análisis de competidores (docs/05): competidores de cada marca, sus cuentas en
 * las redes que lo permiten por API oficial, una foto diaria de sus métricas
 * públicas y sus publicaciones recientes. Tenant-owned (organization_id).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'brand_id']);
        });

        Schema::create('competitor_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('handle', 120);
            $table->string('external_id', 64)->nullable();
            $table->string('display_name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('profile_url')->nullable();
            $table->string('status', 20)->default('active'); // active | error
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['competitor_id', 'provider', 'handle']);
            $table->index(['status', 'last_synced_at']);
        });

        Schema::create('competitor_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('competitor_account_id')->constrained('competitor_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();
            // Totales de los últimos 7 días que da la red (Threads), si los da.
            $table->json('weekly')->nullable();
            $table->timestamps();

            $table->unique(['competitor_account_id', 'date']);
        });

        Schema::create('competitor_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('competitor_account_id')->constrained('competitor_accounts')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->timestamp('published_at')->nullable();
            $table->string('type', 32)->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('permalink')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('views')->nullable();
            $table->timestamps();

            $table->unique(['competitor_account_id', 'external_id']);
            $table->index(['competitor_account_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_posts');
        Schema::dropIfExists('competitor_snapshots');
        Schema::dropIfExists('competitor_accounts');
        Schema::dropIfExists('competitors');
    }
};
