# Decisiones técnicas registradas

## 1. Shared database multi-tenant
Se usa una base compartida con aislamiento por `organization_id`. Motivo: simplicidad operativa inicial, menor coste y facilidad de reporting. Enterprise DB-per-tenant queda fuera de alcance inicial.

## 2. Organization != User
La Organization es el tenant. Permite equipos, agencias y transferencia de propiedad sin reestructurar datos.

## 3. RBAC + permisos granulares
Roles predefinidos + custom roles futuros. La seguridad real se valida por permisos/Policies.

## 4. Modular Monolith
Evita complejidad prematura de microservicios. Los límites de módulo permiten extraer servicios si la escala lo exige.

## 5. Asynchronous publishing
Toda publicación externa usa queue/jobs. Mejora resiliencia, rate limiting y retries.

## 6. Adapter pattern
Social, AI y Payment providers son intercambiables.

## 7. UI en español, código técnico convencional
Maximiza mantenibilidad sin sacrificar experiencia local.

## 8. SUPERADMIN fuera del RBAC normal del customer
Evita escalamiento accidental de privilegios y simplifica auditoría.
