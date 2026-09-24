<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excepciones por organización a los límites/features de su plan (feature flags
 * y acuerdos comerciales), gestionadas por SUPERADMIN. Reemplazan el valor del
 * plan (y de los add-ons) para esa clave.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('organization_entitlement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entitlement_key', 64);
            $table->string('value', 32);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'entitlement_key'], 'org_entitlement_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_entitlement_overrides');
    }
};
