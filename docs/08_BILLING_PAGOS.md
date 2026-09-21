# Billing, planes y pagos

## Principio
Los planes y entitlements son dominio propio. No dependen del objeto Plan de una pasarela.

## Gateways
- Stripe
- Mercado Pago
- Openpay

Cada gateway debe ser:
- activable/desactivable desde SUPERADMIN;
- configurable en test/sandbox y production;
- implementado mediante `PaymentGatewayInterface`;
- capaz de verificar webhooks;
- idempotente.

## Configuración segura
SUPERADMIN puede configurar credenciales pero las claves secretas se almacenan cifradas y se muestran enmascaradas. Cambiar entorno debe ser explícito y auditado.

## Planes
Sugeridos inicialmente:
- Starter
- Growth
- Professional
- Agency
- Enterprise

## Entitlements ejemplo
- brands.max
- social_accounts.max
- team_members.max
- scheduled_posts.month
- storage.gb
- ai_credits.month
- competitors.max
- feature.approvals
- feature.analytics_advanced
- feature.inbox
- feature.automations
- feature.byok
- feature.api
- feature.white_label

## Add-ons
- Brand adicional
- perfiles sociales adicionales
- miembros adicionales
- créditos IA
- almacenamiento
- módulos premium

## Estados suscripción
TRIALING, ACTIVE, PAST_DUE, GRACE, SUSPENDED, CANCELLED, EXPIRED.

## Webhooks
Persistir evento externo con unique provider_event_id. Procesar una sola vez. Conservar payload sanitizado/seguro según necesidad de soporte.
