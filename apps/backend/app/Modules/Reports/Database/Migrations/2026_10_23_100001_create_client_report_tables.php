<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informes para clientes (docs/05): la definición de cada informe de una marca
 * (secciones, periodo, destinatarios y envío programado) y cada informe generado
 * (PDF privado + foto de los datos con que se hizo).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('client_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('period', 32);
            $table->json('sections');
            $table->text('intro')->nullable();
            $table->json('recipients');
            // Envío automático: null, weekly (día ISO 1–7) o monthly (día 1–28), a una hora de la marca.
            $table->string('frequency', 16)->nullable();
            $table->unsignedTinyInteger('send_day')->nullable();
            $table->unsignedTinyInteger('send_hour')->default(8);
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'brand_id']);
            $table->index('next_send_at');
        });

        Schema::create('client_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('client_report_id')->constrained('client_reports')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            // manual (sólo PDF), send (PDF + correo ahora) o scheduled (envío programado).
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->json('data')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('error', 500)->nullable();
            // A quién debía enviarse y a quién se envió ya (un reintento no repite correos).
            $table->json('recipients')->nullable();
            $table->json('delivered_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_report_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_runs');
        Schema::dropIfExists('client_reports');
    }
};
