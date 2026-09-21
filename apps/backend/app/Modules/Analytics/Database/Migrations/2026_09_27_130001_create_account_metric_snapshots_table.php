<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots diarios de métricas de cuenta/página (docs/05 Analytics). Una fila
 * por destino y día; permite comparar periodos y trazar series temporales.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('social_connection_destination_id');
            $table->foreign('social_connection_destination_id', 'acct_snap_dest_fk')
                ->references('id')->on('social_connection_destinations')->cascadeOnDelete();
            $table->string('provider');
            $table->date('date');
            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('engagement')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->unique(['social_connection_destination_id', 'date'], 'acct_snapshot_unique');
            $table->index(['brand_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_metric_snapshots');
    }
};
