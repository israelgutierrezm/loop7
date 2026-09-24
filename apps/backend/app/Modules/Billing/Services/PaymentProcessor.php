<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Contracts\PaymentNotification;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\GatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aplica al billing los hechos de pago normalizados (webhooks o confirmación
 * manual de SUPERADMIN). Es idempotente: una transacción de la pasarela se
 * registra una sola vez y una factura pagada no vuelve a activar nada.
 */
class PaymentProcessor
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly GatewayManager $gateways,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return string  resultado legible para el registro del webhook
     */
    public function process(string $gateway, string $environment, PaymentNotification $n): string
    {
        return DB::transaction(fn (): string => match ($n->type) {
            PaymentNotification::CHECKOUT_COMPLETED => $this->checkoutCompleted($gateway, $environment, $n),
            PaymentNotification::PAYMENT_SUCCEEDED => $this->paymentSucceeded($gateway, $environment, $n),
            PaymentNotification::PAYMENT_FAILED => $this->paymentFailed($gateway, $environment, $n),
            PaymentNotification::SUBSCRIPTION_CANCELLED => $this->subscriptionCancelled($gateway, $n),
            default => 'ignorado: tipo desconocido',
        });
    }

    /**
     * SUPERADMIN confirma el pago de una factura (pago manual u otro medio).
     */
    public function markInvoicePaid(Invoice $invoice, ?string $note = null): string
    {
        return $this->process((string) ($invoice->gateway ?? 'manual'), 'production', new PaymentNotification(
            type: PaymentNotification::CHECKOUT_COMPLETED,
            reference: $invoice->public_id,
            transactionId: 'manual_' . Str::ulid()->toString(),
            amountCents: $invoice->amount_cents,
            currency: $invoice->currency,
        )) . ($note !== null && $note !== '' ? ' · ' . $note : '');
    }

    private function checkoutCompleted(string $gateway, string $environment, PaymentNotification $n): string
    {
        $invoice = $n->reference !== null && $n->reference !== ''
            ? Invoice::query()->withoutGlobalScopes()->where('public_id', $n->reference)->lockForUpdate()->first()
            : null;
        if ($invoice === null) {
            return 'ignorado: factura de referencia no encontrada';
        }
        if ($invoice->status === Invoice::PAID) {
            return 'ignorado: la factura ya estaba pagada';
        }

        $organization = Organization::query()->find($invoice->organization_id);
        $plan = Plan::query()->where('key', (string) ($invoice->meta['plan'] ?? ''))->first();
        if ($organization === null || $plan === null) {
            return 'ignorado: organización o plan inexistente';
        }

        $interval = (string) ($invoice->meta['interval'] ?? 'month');
        $current = $this->subscriptions->find($organization);

        // Openpay/manual pagan periodos sueltos: si ya estaba en este plan, se renueva.
        $isRenewal = $current !== null
            && $current->plan_id === $plan->id
            && $current->gateway === $gateway
            && $n->gatewaySubscriptionId === null
            && $current->grantsAccess()
            && $current->status->value !== 'trialing';

        if ($isRenewal) {
            $subscription = $this->subscriptions->renew($current, $n->periodEnd);
        } else {
            $previousGateway = $current?->gateway;
            $previousId = $current?->gateway_subscription_id;
            $subscription = $this->subscriptions->activatePlan(
                $organization,
                $plan,
                $interval,
                $gateway,
                $n->gatewaySubscriptionId,
                $n->periodEnd,
            );
            $this->cancelPrevious($previousGateway, $previousId, $n->gatewaySubscriptionId);
        }

        $invoice->forceFill([
            'status' => Invoice::PAID,
            'paid_at' => now(),
            'subscription_id' => $subscription->id,
            'gateway' => $gateway,
        ])->save();
        $this->recordTransaction($invoice->organization_id, $invoice->id, $gateway, $environment, $n, 'succeeded', $invoice->amount_cents, $invoice->currency);

        return ($isRenewal ? 'renovado' : 'plan activado') . ': ' . $plan->key . ' (' . $interval . ')';
    }

    private function paymentSucceeded(string $gateway, string $environment, PaymentNotification $n): string
    {
        $subscription = $this->subscriptionFor($gateway, $n);
        if ($subscription === null) {
            return 'ignorado: suscripción de la pasarela no encontrada';
        }
        if ($this->alreadyRecorded($gateway, $n->transactionId)) {
            return 'ignorado: cobro ya registrado';
        }

        $plan = Plan::query()->find($subscription->plan_id);
        $amount = $n->amountCents ?? 0;
        $currency = $n->currency ?? 'USD';

        $this->subscriptions->renew($subscription, $n->periodEnd);

        $invoice = new Invoice();
        $invoice->forceFill([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'gateway' => $gateway,
            'gateway_reference' => $n->transactionId,
            'number' => 'L7-' . now()->format('Y') . '-' . str_pad((string) ((int) Invoice::query()->withoutGlobalScopes()->max('id') + 1), 6, '0', STR_PAD_LEFT),
            'amount_cents' => $amount,
            'currency' => $currency,
            'description' => 'Renovación · Plan ' . ($plan->name ?? ''),
            'status' => Invoice::PAID,
            'issued_at' => now(),
            'paid_at' => now(),
            'meta' => ['plan' => $plan?->key, 'interval' => $subscription->interval],
        ])->save();
        $this->recordTransaction($subscription->organization_id, $invoice->id, $gateway, $environment, $n, 'succeeded', $amount, $currency);

        return 'renovado hasta ' . $subscription->current_period_end?->toDateString();
    }

    private function paymentFailed(string $gateway, string $environment, PaymentNotification $n): string
    {
        $subscription = $this->subscriptionFor($gateway, $n);
        if ($subscription === null) {
            return 'ignorado: suscripción de la pasarela no encontrada';
        }

        $this->subscriptions->enterGrace($subscription, 'payment_failed');
        if (! $this->alreadyRecorded($gateway, $n->transactionId)) {
            $this->recordTransaction($subscription->organization_id, null, $gateway, $environment, $n, 'failed', $n->amountCents ?? 0, $n->currency ?? 'USD');
        }

        return 'cobro rechazado: suscripción en periodo de gracia';
    }

    private function subscriptionCancelled(string $gateway, PaymentNotification $n): string
    {
        $subscription = $this->subscriptionFor($gateway, $n);
        if ($subscription === null) {
            return 'ignorado: suscripción de la pasarela no encontrada';
        }

        $this->subscriptions->markCancelled($subscription);

        return 'suscripción cancelada por la pasarela';
    }

    private function subscriptionFor(string $gateway, PaymentNotification $n): ?Subscription
    {
        if ($n->gatewaySubscriptionId === null || $n->gatewaySubscriptionId === '') {
            return null;
        }

        return $this->subscriptions->findByGatewaySubscription($gateway, $n->gatewaySubscriptionId);
    }

    /**
     * Al cambiar de plan con otra suscripción de pasarela, se cancela la anterior
     * para no cobrar dos veces (best-effort: si falla, queda en el log).
     */
    private function cancelPrevious(?string $gateway, ?string $previousId, ?string $newId): void
    {
        if ($gateway === null || $previousId === null || $previousId === '' || $previousId === $newId) {
            return;
        }

        $record = $this->gateways->record($gateway);
        $adapter = $this->gateways->adapter($gateway);
        if ($record === null || $adapter === null) {
            return;
        }

        try {
            $adapter->cancelSubscription($previousId, $this->gateways->credentials($record));
        } catch (Throwable $e) {
            Log::warning('No se pudo cancelar la suscripción anterior en la pasarela.', [
                'gateway' => $gateway, 'subscription' => $previousId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyRecorded(string $gateway, ?string $transactionId): bool
    {
        return $transactionId !== null && $transactionId !== '' && PaymentTransaction::query()
            ->where('gateway', $gateway)
            ->where('provider_transaction_id', $transactionId)
            ->exists();
    }

    private function recordTransaction(
        int $organizationId,
        ?int $invoiceId,
        string $gateway,
        string $environment,
        PaymentNotification $n,
        string $status,
        int $amountCents,
        string $currency,
    ): void {
        $transaction = PaymentTransaction::query()->create([
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'gateway' => $gateway,
            'environment' => $environment,
            'provider_transaction_id' => $n->transactionId,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => $status,
            'type' => 'charge',
            'meta' => ['notification' => $n->type],
        ]);

        $this->audit->log(AuditAction::PAYMENT_RECORDED, $transaction, [
            'gateway' => $gateway, 'status' => $status, 'amount_cents' => $amountCents, 'currency' => $currency,
        ], organizationId: $organizationId);
    }
}
