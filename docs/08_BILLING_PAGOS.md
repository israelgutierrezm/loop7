# Billing, planes y pagos

## Principio
Los planes y entitlements son dominio propio. No dependen del objeto Plan de una pasarela:
el checkout se crea con importes inline (sin catálogo previo en Stripe/Mercado Pago/Openpay).

## Gateways
Implementados detrás de `PaymentGatewayInterface` (`App\Modules\Payments\Gateways`):

| Pasarela | Flujo | Renovación | Webhook |
|---|---|---|---|
| **Stripe** | Checkout Session en modo suscripción (`price_data` inline, `Idempotency-Key`) | Automática (Stripe cobra) | Firma `Stripe-Signature` HMAC-SHA256, tolerancia 300 s |
| **Mercado Pago** | Preapproval (`init_point`) | Automática (MP cobra) | `x-signature` sobre `id:{data.id};request-id:{x-request-id};ts:{ts};` y consulta del estado a la API |
| **Openpay** | Cargo con redirección (`confirm=false`, `order_id` = id público de la factura) | Manual: al vencer el periodo se pide pagar de nuevo | Basic auth (`webhook_secret` = `usuario:contraseña`); el primer webhook trae `verification_code`, visible en la bitácora de SUPERADMIN |
| **Manual** | Crea una factura pendiente (transferencia/efectivo) | Manual | — (la confirma SUPERADMIN) |

Cada gateway es activable/desactivable desde SUPERADMIN, configurable en test/producción,
verifica webhooks y es idempotente. El pago **manual nunca activa un plan por sí mismo**:
genera una factura `open` que SUPERADMIN marca como pagada.

## Configuración segura
Las credenciales se guardan cifradas y se muestran enmascaradas (`••••4242`). Cambiar de
entorno es explícito y auditado. Moneda por pasarela en su configuración (Stripe/MP: USD por
defecto; Openpay: MXN y país `mx|co|pe`).

## Planes
Starter, Growth, Professional, Agency y Enterprise (semilla no destructiva: `BillingSeeder`
sólo crea lo que falta; lo que edite SUPERADMIN no se sobrescribe). Precios mensual/anual
por moneda y días de prueba por plan.

## Entitlements
Catálogo en código (`App\Modules\Billing\Entitlements\Entitlement`); `-1` = ilimitado.

| Clave | Qué limita | Dónde se aplica |
|---|---|---|
| `brands.max` | Marcas | Crear marca |
| `social_accounts.max` | Cuentas sociales conectadas | Conectar (OAuth y manual) |
| `team_members.max` | Miembros | Invitar (cuenta miembros + invitaciones pendientes) |
| `scheduled_posts.month` | Publicaciones (una por red) programadas o publicadas en el mes | Programar / publicar ahora |
| `storage.gb` | Almacenamiento de la biblioteca | Subida y guardado de imágenes IA |
| `ai_credits.month` | Créditos de IA | Cada generación |
| `knowledge_documents.max` | Documentos del Brand Brain (Starter 5, Growth 25, Professional 100, Agency 500, Enterprise ilimitados) | Subir documento (RAG) |
| `feature.approvals` | Flujo de aprobación (sin él se aprueba directo) | Enviar a revisión |
| `feature.analytics_advanced` | Exportación de analítica | Export CSV |
| `feature.inbox` | Inbox | Todo el módulo |
| `feature.automations` | Automatizaciones | Todo el módulo y el motor |
| `feature.byok` | Claves de IA propias | Claves de IA |
| `feature.api` | API pública y MCP | API keys |
| `feature.white_label` | Marca blanca | Configuración → Marca blanca |
| `feature.custom_roles` | Roles personalizados (Professional, Agency, Enterprise) | Crear y editar roles (`POST|PATCH /roles`); eliminar siempre se permite |

Sin suscripción vigente (prueba vencida, suspendida, cancelada) todos los límites valen 0
y las funciones quedan desactivadas (los datos se conservan). Un 402
`plan_limit_reached` indica en `errors.entitlement` qué límite se alcanzó.

## Add-ons y excepciones
- **Add-ons** (catálogo gestionable por SUPERADMIN): suman a un límite numérico por
  unidad (p. ej. `ai-credits-1000` × 2 = +2000 créditos).
- **Excepciones por organización** (`organization_entitlement_overrides`): SUPERADMIN
  puede fijar cualquier límite o función para una organización concreta (pilotos,
  acuerdos comerciales); reemplazan al valor del plan y quedan auditadas.

## Estados de la suscripción
`trialing → active → grace → suspended`, además de `cancelled` y `expired`
(`past_due` reservado). El comando `billing:sync-subscriptions` (cada hora) aplica:

1. Aviso único "tu prueba termina pronto" 3 días antes (`trial_reminder_sent_at`).
2. Prueba vencida → `expired`.
3. Fin de periodo con cancelación programada → `cancelled`.
4. Fin de periodo sin cobro → `grace` (conserva el acceso los días de gracia configurados).
5. Gracia agotada → `suspended`.

Cada transición se audita y emite `SubscriptionChanged`; el módulo Notifications avisa a
quienes tienen `billing.view` (prueba por terminar, pago no recibido, suspensión, fin de
prueba, cancelación, plan activado).

## Webhooks
`POST /api/v1/webhooks/payments/{gateway}`: se verifica la firma/credencial, se persiste
el evento con `unique(gateway, provider_event_id)` (idempotencia) y se procesa en cola
(`ProcessPaymentWebhook`). El evento se normaliza a `PaymentNotification`
(`payment_succeeded`, `payment_failed`, `subscription_cancelled`…). La bitácora de
SUPERADMIN permite ver y reintentar eventos fallidos.

## Endpoints del cliente
- `GET /billing/plans`, `GET /billing/gateways` (catálogo público).
- `GET /billing/subscription` (plan, estado, uso contra límites), `GET /billing/invoices`.
- `POST /billing/subscribe` `{plan, interval, gateway}` → URL de pago o factura manual.
- `POST /billing/cancel` (al final del periodo) y `POST /billing/resume`.

## Probar conexión (SUPERADMIN)
`verifyCredentials(credentials)` por pasarela: Stripe `GET /v1/balance`, Mercado Pago
`GET /users/me`, Openpay consulta del propio comercio, Manual no-op. Endpoint
`POST /platform/payment-gateways/{gateway}/test` sobre el **entorno activo**; devuelve
`{ ok, environment, message }` sin exponer secretos.
