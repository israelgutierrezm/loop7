<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca blanca (feature.white_label): nombre visible, color principal y logo
 * con los que la plataforma se presenta a los miembros de la organización.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->json('branding')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('branding');
        });
    }
};
