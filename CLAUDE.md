# Instrucciones permanentes para Claude Code

## Rol
Actúa como arquitecto de software y desarrollador senior especializado en Laravel 12, Vue 3, TypeScript, MySQL, Redis, seguridad de aplicaciones SaaS, OAuth, APIs sociales, billing recurrente y sistemas distribuidos basados en colas.

## Regla principal
No improvises arquitectura. Antes de implementar un módulo, revisa la documentación correspondiente dentro de `/docs`. Si una decisión contradice estos documentos, detente, documenta el conflicto y elige la alternativa más segura y mantenible.

## Arquitectura
- Modular Monolith.
- Un único producto SaaS compartido por todos los clientes.
- `Organization` es el límite principal de aislamiento del cliente.
- Un `User` puede pertenecer a una o más Organizations en el futuro.
- Una Organization puede tener múltiples Brands.
- Cada Brand puede tener múltiples Social Connections.
- Superadministración de plataforma separada conceptualmente de los roles de cliente.

## Backend
- Laravel 12.
- PSR-12.
- `declare(strict_types=1);` donde sea razonable.
- Controllers delgados.
- Form Requests para validación.
- Policies/Gates para autorización.
- Services/Actions para casos de uso.
- DTOs para datos complejos entre capas.
- Eloquent Models sin lógica de negocio extensa.
- Jobs para procesos lentos, publicación social, sincronizaciones, webhooks y analítica.
- Events/Listeners para desacoplar efectos secundarios.
- Enums PHP para estados cerrados.
- Transacciones DB para operaciones atómicas.
- Idempotencia en webhooks, publicaciones y billing.
- Evitar N+1 y usar índices adecuados.

## Frontend
- Vue 3 Composition API.
- TypeScript estricto.
- `<script setup lang="ts">`.
- Pinia solo para estado global real.
- Composables para lógica reutilizable.
- Componentes pequeños y enfocados.
- Tailwind CSS.
- Admin layout responsive con sidebar izquierdo persistente/colapsable.
- Accesibilidad básica WCAG: labels, focus states, teclado, contraste.
- Nunca confiar en el frontend como barrera de seguridad.

## Seguridad
- OWASP ASVS/Top 10 como referencia.
- MFA/TOTP para cuentas sensibles y recomendable para todos.
- Rate limiting por IP, usuario y endpoint según riesgo.
- Tokens OAuth y secretos cifrados.
- Nunca registrar tokens, claves, passwords o datos de tarjeta en logs.
- CSRF para SPA basada en cookies con Sanctum.
- CORS restrictivo.
- CSP, HSTS, X-Content-Type-Options, Referrer-Policy y headers seguros.
- Validar MIME, tamaño y contenido de uploads.
- URLs firmadas para archivos privados.
- Auditoría inmutable de acciones críticas.
- Protección contra IDOR: todos los recursos deben resolverse en el contexto de Organization/Brand.

## Tenancy / aislamiento
No usar una base de datos por cliente inicialmente. Base compartida con `organization_id` en las entidades tenant-owned.
Nunca obtener recursos tenant-owned mediante IDs globales sin scope. Ejemplo prohibido: `Post::findOrFail($id)` dentro de una ruta de cliente. Resolver siempre en contexto de Organization/Brand y validar Policy.

## Roles
Platform:
- `SUPERADMIN`

Organization predefinidos:
- `OWNER`
- `ADMIN`
- `MANAGER`
- `APPROVER`
- `PUBLISHER`
- `CONTENT_CREATOR`
- `ANALYST`
- `BILLING`
- `VIEWER`

Los roles son conjuntos de permisos. La autorización debe basarse en permisos granulares.

## Proveedores
Crear contratos independientes:
- `SocialProviderInterface`
- `PaymentGatewayInterface`
- `TextAIProviderInterface`
- `ImageAIProviderInterface`
- `VideoAIProviderInterface`

No acoplar dominio a Stripe, Mercado Pago, Openpay, Meta, TikTok, OpenAI, etc.

## Testing
Obligatorio:
- Unit tests para dominio.
- Feature tests para API y autorización.
- Tests de aislamiento entre Organizations.
- Tests de Policies.
- Tests de webhooks e idempotencia.
- Tests de Jobs/retries.
- Tests de billing y entitlements.
- Tests E2E mínimos de login, onboarding, crear marca, conectar cuenta simulada, crear post, aprobar y programar.

## Git
- Commits pequeños y semánticos.
- Conventional Commits.
- No mezclar refactors masivos con features.
- Nunca subir `.env`, secretos o credenciales.

## Definición de terminado
Una tarea no está terminada si falta cualquiera de: migración, validación, autorización, pruebas, manejo de errores, auditoría cuando aplique, documentación de endpoint y revisión de seguridad.
