<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las marcas se creaban siempre en UTC (el alta no enviaba zona y no había dónde
 * cambiarla). Ahora heredan la zona de su organización: se corrige el valor por
 * defecto de las existentes, base de sus mejores horarios para publicar.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('brands')
            ->where('timezone', 'UTC')
            ->update([
                'timezone' => DB::raw("COALESCE((SELECT o.timezone FROM organizations o WHERE o.id = brands.organization_id), 'UTC')"),
            ]);
    }

    public function down(): void
    {
        // Sin vuelta atrás: no se sabe qué marcas estaban en UTC por elección.
    }
};
