<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de entitlements vive en código (App\Modules\Billing\Entitlements\
 * Entitlement): la tabla sólo se sincronizaba desde el seeder y nadie la leía.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('entitlements');
    }

    public function down(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('type', 16);
            $table->string('label');
            $table->timestamps();
        });
    }
};
