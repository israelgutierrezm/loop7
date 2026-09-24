<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avatar_path nunca tuvo subida ni se mostraba (la UI usa iniciales) y el API
 * aceptaba una ruta arbitraria: se retira.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('avatar_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('timezone');
        });
    }
};
