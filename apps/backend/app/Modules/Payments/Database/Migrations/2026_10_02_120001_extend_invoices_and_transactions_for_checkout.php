<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout real: cada cobro nace como factura pendiente (su public_id viaja a la
 * pasarela como referencia) y cada transacción se enlaza a su factura. La tabla
 * provider_product_mappings no se usa (el precio se envía en línea) y se elimina.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('gateway', 32)->nullable()->after('subscription_id');
            $table->string('gateway_reference')->nullable()->after('gateway');
            $table->string('description')->nullable()->after('currency');
            $table->index(['gateway', 'gateway_reference'], 'invoices_gateway_ref_idx');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->after('organization_id')
                ->constrained('invoices')->nullOnDelete();
        });

        // Resultado legible del procesamiento (visible en SUPERADMIN → Webhooks).
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->text('result')->nullable()->after('error');
        });

        Schema::dropIfExists('provider_product_mappings');
    }

    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('result');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_gateway_ref_idx');
            $table->dropColumn(['gateway', 'gateway_reference', 'description']);
        });
    }
};
