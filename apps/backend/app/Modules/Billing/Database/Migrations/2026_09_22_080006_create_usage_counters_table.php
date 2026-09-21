<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key'); // p.ej. scheduled_posts.month, ai_credits.month
            $table->string('period', 16); // 'YYYY-MM' o 'total'
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
