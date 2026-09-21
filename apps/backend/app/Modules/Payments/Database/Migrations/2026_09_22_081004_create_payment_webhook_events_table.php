<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('environment', 16);
            $table->string('provider_event_id');
            $table->string('event_type')->nullable();
            $table->json('payload')->nullable(); // saneado
            $table->string('status', 24)->default('received'); // received | processed | failed | ignored
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Idempotencia: un evento de una pasarela se procesa una sola vez.
            $table->unique(['gateway', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
