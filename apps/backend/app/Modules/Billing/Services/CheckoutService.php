<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Payments\Contracts\CheckoutRequest;
use App\Modules\Payments\Contracts\CheckoutResult;
use App\Modules\Payments\Models\Invoice;
use App\Modules\Payments\Services\GatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Inicia el pago de un plan: crea la factura pendiente (su id público viaja a
 * la pasarela como referencia) y pide a la pasarela la URL de pago o, en pago
 * manual, las instrucciones. El plan sólo se activa al confirmarse el pago.
 */
class CheckoutService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{status: string, redirect_url: string|null, message: string|null, invoice: Invoice}
     */
    public function start(Organization $organization, Plan $plan, string $interval, string $gatewayKey, User $user): array
    {
        $gateway = $this->gateways->record($gatewayKey);
        $adapter = $this->gateways->adapter($gatewayKey);
        if ($gateway === null || ! $gateway->is_enabled || $adapter === null) {
            throw ValidationException::withMessages(['gateway' => 'La pasarela de pago seleccionada no está disponible.']);
        }

        $currency = $this->gateways->currency($gateway);
        $price = $plan->prices()
            ->where('interval', $interval)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->first();
        if ($price === null || $price->amount_cents <= 0) {
            throw ValidationException::withMessages([
                'plan' => "El plan {$plan->name} no tiene precio {$this->intervalLabel($interval)} en {$currency} para esta pasarela.",
            ]);
        }

        // Una solicitud pendiente anterior del mismo cliente queda sustituida.
        Invoice::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', Invoice::OPEN)
            ->update(['status' => Invoice::VOID]);

        $invoice = $this->createInvoice($organization, $plan, $interval, $gatewayKey, $price->amount_cents, $currency);

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $result = $adapter->startCheckout(new CheckoutRequest(
            reference: $invoice->public_id,
            organizationId: $organization->public_id,
            planKey: $plan->key,
            planName: $plan->name,
            interval: $interval,
            amountCents: $price->amount_cents,
            currency: $currency,
            customerEmail: $organization->billing_email ?: $user->email,
            customerName: $organization->name,
            successUrl: $frontend . '/app/billing?checkout=success',
            cancelUrl: $frontend . '/app/billing?checkout=cancelled',
        ), $this->gateways->credentials($gateway));

        $invoice->forceFill([
            'gateway_reference' => $result->gatewayReference,
            'meta' => [...($invoice->meta ?? []), 'checkout_url' => $result->redirectUrl],
        ])->save();

        $this->audit->log(AuditAction::CHECKOUT_STARTED, $invoice, [
            'plan' => $plan->key, 'interval' => $interval, 'gateway' => $gatewayKey, 'status' => $result->status,
        ], organizationId: $organization->id);

        return [
            'status' => $result->status,
            'redirect_url' => $result->status === CheckoutResult::REDIRECT ? $result->redirectUrl : null,
            'message' => $result->message,
            'invoice' => $invoice,
        ];
    }

    private function createInvoice(
        Organization $organization,
        Plan $plan,
        string $interval,
        string $gateway,
        int $amountCents,
        string $currency,
    ): Invoice {
        $subscription = $this->subscriptions->find($organization);

        // El número es correlativo; si dos altas coinciden, se reintenta con el siguiente.
        for ($attempt = 0; ; $attempt++) {
            try {
                $invoice = new Invoice();
                $invoice->forceFill([
                    'organization_id' => $organization->id,
                    'subscription_id' => $subscription?->id,
                    'gateway' => $gateway,
                    'number' => $this->nextNumber(),
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'description' => 'Plan ' . $plan->name . ' · ' . $this->intervalLabel($interval),
                    'status' => Invoice::OPEN,
                    'issued_at' => now(),
                    'meta' => ['plan' => $plan->key, 'interval' => $interval],
                ])->save();

                return $invoice;
            } catch (QueryException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    private function nextNumber(): string
    {
        $next = (int) Invoice::query()->withoutGlobalScopes()->max('id') + 1;

        return 'L7-' . now()->format('Y') . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function intervalLabel(string $interval): string
    {
        return $interval === 'year' ? 'anual' : 'mensual';
    }
}
