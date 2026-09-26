<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles personalizados de una Organization: nombre visible y descripción. Los
 * predefinidos (globales) toman su etiqueta de OrganizationRole.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('name');
            $table->string('description', 300)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }
};
