<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token propio del destino (p. ej. page token de Meta, que no caduca). Se guarda
 * cifrado (cast `encrypted`) y nunca se expone al frontend.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('social_connection_destinations', function (Blueprint $table): void {
            $table->text('access_token')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('social_connection_destinations', function (Blueprint $table): void {
            $table->dropColumn('access_token');
        });
    }
};
