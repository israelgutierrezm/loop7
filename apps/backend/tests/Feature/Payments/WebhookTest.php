<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_un_webhook_duplicado_no_se_reprocesa(): void
    {
        $payload = ['id' => 'evt_manual_1', 'type' => 'subscription.updated'];

        $this->postJson('/api/v1/webhooks/payments/manual', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/payments/manual', $payload)->assertOk();

        // Idempotencia: sólo un registro para ese provider_event_id.
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_stripe_acepta_firma_valida_y_rechaza_invalida(): void
    {
        $gateway = PaymentGateway::query()->where('key', 'stripe')->firstOrFail();
        $gateway->update(['is_enabled' => true, 'environment' => 'test']);
        PaymentGatewayCredential::query()->create([
            'payment_gateway_id' => $gateway->id,
            'environment' => 'test',
            'key' => 'webhook_secret',
            'value' => 'whsec_test_secret',
        ]);

        $payload = json_encode(['id' => 'evt_stripe_1', 'type' => 'invoice.paid']);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test_secret');

        // Firma válida → procesado.
        $this->call(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $payload,
        )->assertOk();

        $this->assertDatabaseHas('payment_webhook_events', [
            'gateway' => 'stripe',
            'provider_event_id' => 'evt_stripe_1',
            'status' => 'processed',
        ]);

        // Firma inválida → 401.
        $this->call(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=firma_incorrecta",
            ],
            json_encode(['id' => 'evt_stripe_2', 'type' => 'invoice.paid']),
        )->assertStatus(401);
    }
}
