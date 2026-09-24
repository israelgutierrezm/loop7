<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $gateway = PaymentGateway::query()->where('key', 'stripe')->firstOrFail();
        $gateway->update(['is_enabled' => true, 'environment' => 'test']);
        PaymentGatewayCredential::query()->create([
            'payment_gateway_id' => $gateway->id,
            'environment' => 'test',
            'key' => 'webhook_secret',
            'value' => 'whsec_test_secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function sendStripe(array $event, ?int $timestamp = null, ?string $secret = 'whsec_test_secret'): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, (string) $secret);

        return $this->call('POST', '/api/v1/webhooks/payments/stripe', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    public function test_un_webhook_duplicado_no_se_reprocesa(): void
    {
        $event = ['id' => 'evt_1', 'type' => 'customer.created', 'data' => ['object' => []]];

        $this->sendStripe($event)->assertOk();
        $this->sendStripe($event)->assertOk()->assertJsonPath('message', 'Evento ya recibido.');

        // Idempotencia: sólo un registro para ese provider_event_id.
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseHas('payment_webhook_events', ['provider_event_id' => 'evt_1', 'status' => 'ignored']);
    }

    public function test_stripe_rechaza_firma_invalida_o_caducada(): void
    {
        $event = ['id' => 'evt_2', 'type' => 'invoice.paid'];

        $this->sendStripe($event, secret: 'otro_secreto')->assertStatus(401);
        // Marca de tiempo fuera de tolerancia (anti-replay).
        $this->sendStripe($event, timestamp: time() - 3600)->assertStatus(401);

        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_la_pasarela_manual_no_acepta_webhooks(): void
    {
        $this->postJson('/api/v1/webhooks/payments/manual', ['id' => 'evt_x', 'type' => 'paid'])
            ->assertStatus(401);

        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_pasarela_deshabilitada_responde_404(): void
    {
        PaymentGateway::query()->where('key', 'stripe')->update(['is_enabled' => false]);

        $this->sendStripe(['id' => 'evt_3', 'type' => 'invoice.paid'])->assertNotFound();
    }
}
