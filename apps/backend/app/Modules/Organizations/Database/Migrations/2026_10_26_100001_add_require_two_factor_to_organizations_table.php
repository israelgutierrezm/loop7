<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controles Enterprise (feature.require_two_factor): la organización exige la
 * verificación en dos pasos a todos sus miembros.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('require_two_factor')->default(false)->after('branding');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('require_two_factor');
        });
    }
};
