<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('social_connection_destinations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('social_connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('type', 48)->default('account');
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['social_connection_id', 'external_id'], 'social_dest_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connection_destinations');
    }
};
