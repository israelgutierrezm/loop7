<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El logo de una marca es una imagen de su propia biblioteca de medios (se
 * sirve con URL firmada como el resto). logo_path nunca se pudo establecer.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->foreignId('logo_media_id')->nullable()->after('description')
                ->constrained('media_assets')->nullOnDelete();
        });

        if (Schema::hasColumn('brands', 'logo_path')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->dropColumn('logo_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_media_id');
            $table->string('logo_path')->nullable()->after('description');
        });
    }
};
