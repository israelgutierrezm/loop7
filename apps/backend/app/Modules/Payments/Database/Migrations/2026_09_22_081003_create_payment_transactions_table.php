<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('gateway', 32);
            $table->string('environment', 16);
            $table->string('provider_transaction_id')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 32);
            $table->string('type', 32)->default('charge'); // charge | refund
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['gateway', 'provider_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
