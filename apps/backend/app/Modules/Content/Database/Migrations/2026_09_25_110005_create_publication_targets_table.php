<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('publication_targets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('post_variant_id')->constrained('post_variants')->cascadeOnDelete();
            $table->foreignId('social_connection_destination_id')->nullable()
                ->constrained('social_connection_destinations')->nullOnDelete();

            $table->string('status', 24)->default('pending')->index();
            $table->string('remote_id')->nullable();
            $table->string('remote_url')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();
        });

        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_target_id')->constrained('publication_targets')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 24);
            $table->json('response')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
        Schema::dropIfExists('publication_targets');
    }
};
