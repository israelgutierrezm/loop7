<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanPrice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentGatewayCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flujo completo de cobro por pasarela contra APIs simuladas: checkout →
 * webhook verificado → plan activo, factura pagada y transacción registrada.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $config
     */
    private function enable(string $key, array $credentials, array $config = []): void
    {
        $gateway = PaymentGateway::query()->where('key', $key)->firstOrFail();
        $gateway->forceFill(['is_enabled' => true, 'environment' => 'test', 'config' => [...($gateway->config ?? []), ...$config]])->save();
        foreach ($credentials as $name => $value) {
            PaymentGatewayCredential::query()->create([
                'payment_gateway_id' => $gateway->id, 'environment' => 'test', 'key' => $name, 'value' => $value,
            ]);
        }
    }

    private function subscription(Organization $org): Subscription
    {
        return Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function stripeWebhook(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $t = time();

        return $this->call('POST', '/api/v1/webhooks/payments/stripe', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $payload, 'whsec_1'),
        ], $payload);
    }

    public function test_stripe_checkout_webhooks_y_ciclo_de_vida(): void
    {
        $this->enable('stripe', ['secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_1']);
        [$owner, $org] = $this->createOwnerWithOrganization();

        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.test/cs_1'])]);

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'professional', 'interval' => 'month', 'gateway' => 'stripe'])
            ->assertOk()
            ->assertJsonPath('data.status', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://checkout.stripe.test/cs_1');

        $reference = (string) $response->json('data.invoice.id');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'checkout/sessions')
            && $r['mode'] === 'subscription'
            && $r['client_reference_id'] === $reference
            && $r['line_items'][0]['price_data']['unit_amount'] === 9900
            && $r['line_items'][0]['price_data']['recurring']['interval'] === 'month'
            && $r->hasHeader('Idempotency-Key', 'checkout-' . $reference));
        $this->assertSame('trialing', $this->subscription($org)->status->value); // aún sin pago

        // 1) Pago confirmado → plan activo.
        $this->stripeWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'mode' => 'subscription', 'payment_status' => 'paid', 'client_reference_id' => $reference,
            'subscription' => 'sub_1', 'invoice' => 'in_1', 'amount_total' => 9900, 'currency' => 'usd',
        ]]])->assertOk();

        $subscription = $this->subscription($org);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('professional', $subscription->plan?->key);
        $this->assertSame('sub_1', $subscription->gateway_subscription_id);
        $this->assertSame(Invoice::PAID, Invoice::query()->withoutGlobalScopes()->where('public_id', $reference)->value('status'));
        $this->assertDatabaseHas('payment_transactions', ['provider_transaction_id' => 'in_1', 'status' => 'succeeded']);

        // 2) Primer invoice.paid (alta): no duplica; renovación: extiende el periodo y factura.
        $this->stripeWebhook(['id' => 'evt_2', 'type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_1', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_create', 'amount_paid' => 9900, 'currency' => 'usd',
        ]]])->assertOk();
        $periodEnd = now()->addMonths(2)->startOfDay();
        $this->stripeWebhook(['id' => 'evt_3', 'type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_2', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 9900, 'currency' => 'usd',
            'parent' => ['subscription_details' => ['subscription' => 'sub_1']],
            'lines' => ['data' => [['period' => ['end' => $periodEnd->timestamp]]]],
        ]]])->assertOk();

        $this->assertDatabaseCount('payment_transactions', 2);
        $this->assertTrue($this->subscription($org)->current_period_end?->equalTo($periodEnd));

        // 3) Cobro rechazado → gracia (conserva acceso); 4) cancelación en Stripe → cancelada.
        $this->stripeWebhook(['id' => 'evt_4', 'type' => 'invoice.payment_failed', 'data' => ['object' => [
            'id' => 'in_3', 'subscription' => 'sub_1', 'amount_due' => 9900, 'currency' => 'usd',
        ]]])->assertOk();
        $this->assertSame('grace', $this->subscription($org)->status->value);

        $this->stripeWebhook(['id' => 'evt_5', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1']]])->assertOk();
        $this->assertSame('cancelled', $this->subscription($org)->status->value);
    }

    public function test_stripe_cancelacion_del_cliente_se_programa_en_stripe(): void
    {
        $this->enable('stripe', ['secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_1']);
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'growth');
        $this->subscription($org)->forceFill(['gateway' => 'stripe', 'gateway_subscription_id' => 'sub_9'])->save();

        Http::fake(['api.stripe.com/v1/subscriptions/sub_9' => Http::response(['id' => 'sub_9'])]);

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/billing/cancel')->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['cancel_at_period_end'] === 'true');

        $this->actingInOrganization($owner, $org)->postJson('/api/v1/billing/resume')->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['cancel_at_period_end'] === 'false');
    }

    public function test_mercado_pago_preapproval_y_webhook_firmado(): void
    {
        $this->enable('mercadopago', ['secret_key' => 'APP_USR-1', 'webhook_secret' => 'mp_secret']);
        PlanPrice::query()->create([
            'plan_id' => Plan::query()->where('key', 'growth')->value('id'), 'interval' => 'month', 'currency' => 'MXN', 'amount_cents' => 89900,
        ]);
        [$owner, $org] = $this->createOwnerWithOrganization();

        $reference = '';
        Http::fake(function (Request $r) use (&$reference) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/preapproval')) {
                return Http::response(['id' => 'pre_1', 'init_point' => 'https://mp.test/checkout/pre_1']);
            }

            // GET /preapproval/{id}: el estado real se consulta a la API (no al cuerpo del webhook).
            return Http::response([
                'id' => 'pre_1', 'status' => 'authorized', 'external_reference' => $reference,
                'auto_recurring' => ['transaction_amount' => 899, 'currency_id' => 'MXN'],
            ]);
        });

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'month', 'gateway' => 'mercadopago'])
            ->assertOk()
            ->assertJsonPath('data.redirect_url', 'https://mp.test/checkout/pre_1');
        $reference = (string) $response->json('data.invoice.id');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/preapproval')
            && $r['external_reference'] === $reference
            && $r['auto_recurring']['transaction_amount'] === 899.0
            && $r['auto_recurring']['currency_id'] === 'MXN');

        // Firma x-signature: HMAC-SHA256 del manifest id;request-id;ts.
        $ts = (string) time();
        $manifest = "id:pre_1;request-id:req-1;ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, 'mp_secret');
        $body = ['id' => 555, 'type' => 'subscription_preapproval', 'action' => 'updated', 'data' => ['id' => 'pre_1']];

        $this->postJson('/api/v1/webhooks/payments/mercadopago?data.id=pre_1&type=subscription_preapproval', $body, [
            'x-signature' => "ts={$ts},v1=bad",
            'x-request-id' => 'req-1',
        ])->assertStatus(401);

        $this->postJson('/api/v1/webhooks/payments/mercadopago?data.id=pre_1&type=subscription_preapproval', $body, [
            'x-signature' => "ts={$ts},v1={$signature}",
            'x-request-id' => 'req-1',
        ])->assertOk();

        $subscription = $this->subscription($org);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('mercadopago', $subscription->gateway);
        $this->assertSame('pre_1', $subscription->gateway_subscription_id);
    }

    public function test_openpay_cargo_con_redireccion_y_webhook_basic_auth(): void
    {
        $this->enable('openpay', ['merchant_id' => 'm123', 'secret_key' => 'sk_op', 'webhook_secret' => 'loop7:clave'], ['currency' => 'MXN', 'country' => 'mx']);
        PlanPrice::query()->create([
            'plan_id' => Plan::query()->where('key', 'growth')->value('id'), 'interval' => 'month', 'currency' => 'MXN', 'amount_cents' => 89900,
        ]);
        [$owner, $org] = $this->createOwnerWithOrganization();

        Http::fake(['sandbox-api.openpay.mx/v1/m123/charges' => Http::response([
            'id' => 'tr_1', 'payment_method' => ['type' => 'redirect', 'url' => 'https://sandbox-api.openpay.mx/v1/m123/charges/tr_1/card_capture'],
        ])]);

        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/billing/subscribe', ['plan' => 'growth', 'interval' => 'month', 'gateway' => 'openpay'])
            ->assertOk()
            ->assertJsonPath('data.status', 'redirect');
        $reference = (string) $response->json('data.invoice.id');

        Http::assertSent(fn (Request $r) => $r['order_id'] === $reference
            && $r['confirm'] === 'false'
            && $r['amount'] === 899.0
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('sk_op:')));

        $auth = ['Authorization' => 'Basic ' . base64_encode('loop7:clave')];

        // Verificación del webhook: se registra y su código queda visible para SUPERADMIN.
        $this->postJson('/api/v1/webhooks/payments/openpay', ['type' => 'verification', 'verification_code' => 'AbC123'], $auth)->assertOk();
        $this->postJson('/api/v1/webhooks/payments/openpay', ['type' => 'charge.succeeded'], ['Authorization' => 'Basic ' . base64_encode('x:y')])
            ->assertStatus(401);

        $this->postJson('/api/v1/webhooks/payments/openpay', ['type' => 'charge.succeeded', 'transaction' => [
            'id' => 'tr_1', 'order_id' => $reference, 'status' => 'completed', 'amount' => 899, 'currency' => 'MXN',
        ]], $auth)->assertOk();

        $this->assertSame('active', $this->subscription($org)->status->value);
        $this->assertSame('openpay', $this->subscription($org)->gateway);

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $codes = collect($this->getJson('/api/v1/platform/webhook-events?gateway=openpay')->assertOk()->json('data'))
            ->pluck('verification_code')->filter()->values()->all();
        $this->assertSame(['AbC123'], $codes);
    }
}
