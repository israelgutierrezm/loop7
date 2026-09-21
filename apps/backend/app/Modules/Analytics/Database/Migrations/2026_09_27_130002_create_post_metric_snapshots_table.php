<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots diarios de métricas por publicación (docs/05 Analytics). Una fila por
 * PublicationTarget y día; base de "performance por post" y top de contenido.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('post_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('publication_target_id')
                ->constrained('publication_targets')->cascadeOnDelete();
            $table->string('provider');
            $table->string('remote_id')->nullable();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('engagement')->default(0);
            $table->timestamps();

            $table->unique(['publication_target_id', 'date'], 'post_snapshot_unique');
            $table->index(['brand_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metric_snapshots');
    }
};
