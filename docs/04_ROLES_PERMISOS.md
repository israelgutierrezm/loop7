# Roles y permisos

## Ámbitos separados
### Platform
- SUPERADMIN

### Organization
- OWNER
- ADMIN
- MANAGER
- APPROVER
- PUBLISHER
- CONTENT_CREATOR
- ANALYST
- BILLING
- VIEWER

## Principio
Los roles agrupan permisos. Las Policies verifican permisos + Organization + Brand + estado + entitlement.

## Permisos base
### Organization
- organization.view
- organization.update
- organization.delete
- organization.transfer_ownership

### Members
- members.view
- members.invite
- members.update
- members.remove
- roles.view
- roles.create
- roles.update
- roles.delete
- roles.assign

### Brands
- brands.view
- brands.create
- brands.update
- brands.delete
- brands.manage_access

### Social Accounts
- social_accounts.view
- social_accounts.connect
- social_accounts.reconnect
- social_accounts.disconnect
- social_accounts.manage
- social_accounts.analytics
- social_accounts.inbox

### Content
- content.view
- content.create
- content.update
- content.delete
- content.ai_generate
- content.submit_for_review
- content.approve
- content.reject
- content.schedule
- content.publish_now

### Campaigns
- campaigns.view
- campaigns.create
- campaigns.update
- campaigns.delete

### Analytics
- analytics.view
- analytics.export

### Billing
- billing.view
- billing.invoices
- billing.change_plan
- billing.payment_methods
- billing.cancel_subscription

### AI
- ai.use
- ai.generate_text
- ai.generate_image
- ai.generate_video
- ai.view_usage
- ai.manage_own_keys

## Brand Access
Un miembro puede tener acceso a todas las Brands o a un subconjunto. Un permiso no concede acceso a una Brand fuera de su asignación.

Se gestiona desde **Equipo** (`PATCH /organization/members/{user}` con `all_brands_access` y
`brands`), con `brands.manage_access`. Quien está limitado a ciertas marcas sólo gestiona
esas: no puede conceder "todas" ni tocar asignaciones de marcas a las que no accede. Al
quitar a un miembro se borran sus asignaciones. Aplicación en el backend: ver docs/03.

## Roles personalizados
Cada Organization puede crear sus roles (Equipo → **Roles y permisos**, `/app/team/roles`)
combinando permisos del catálogo; se asignan e invitan igual que los predefinidos.
- Requieren la función de plan `feature.custom_roles` para crear y editar (docs/08).
- Son roles de spatie con `organization_id` y nombre interno `custom_<ulid>`; lo visible
  es su etiqueta (`roles.label`, única en la organización y distinta de las
  predefinidas) y su descripción. Nunca se ven ni se asignan desde otra organización.
- Nadie concede permisos que no tiene, y `organization.delete` /
  `organization.transfer_ownership` son exclusivos del propietario.
- Sólo se edita o elimina un rol cuyos permisos ya tiene quien actúa, y nunca el rol que
  uno mismo tiene. No se elimina un rol en uso (miembros o invitaciones pendientes: 409).
- Endpoints: `GET /roles` (catálogo con permisos, `assignable`, `permission_groups`
  etiquetados y `custom_roles_available`), `POST /roles`, `PATCH /roles/{rol}`,
  `DELETE /roles/{rol}` (`roles.create|update|delete`). Auditados: `role.created`,
  `role.updated` (permisos añadidos y quitados), `role.deleted`.

## Asignación de roles
- `OWNER` nunca se asigna ni se invita: se transfiere.
- Un rol (predefinido o personalizado) sólo lo asigna o invita quien ya tiene **todos** sus
  permisos (`MembershipService::assignableRoles`). Así un `MANAGER` asigna de MANAGER hacia
  abajo, pero no ADMIN ni BILLING, y no puede cambiar el rol de un ADMIN; un ADMIN sí puede
  nombrar ADMIN.
- Nadie cambia su propio rol ni su acceso a marcas.
- Suspender el acceso de un miembro (`PATCH /organization/members/{user}` con
  `status: suspended|active`) exige `members.remove` y la misma jerarquía que los roles
  (un MANAGER no suspende a un ADMIN). Conserva rol y marcas, pierde el acceso
  (`403 organization_not_resolved`) y deja de recibir avisos y asignaciones. Auditado
  (`member.suspended` / `member.reactivated`). Si le quitan o suspenden con la sesión
  abierta, el SPA recarga sus organizaciones.
- `GET /roles` devuelve `assignable`: los roles que el usuario actual puede asignar.

## Reglas críticas
- Solo OWNER puede transferir propiedad (`POST /organization/transfer-ownership`, con su
  contraseña): el nuevo propietario debe ser un miembro activo, pasa a OWNER con acceso a
  todas las marcas y el anterior queda como ADMIN. Auditado.
- OWNER no puede eliminarse sin transferir propiedad.
- Solo OWNER elimina la organización (`DELETE /organization`): escribe su nombre y su
  contraseña, y se rechaza (`409 subscription_active`) mientras la pasarela siga cobrando
  sola. El borrado es lógico y atómico con su limpieza (`OrganizationDeleted`): marcas
  (publicaciones canceladas, cuentas desconectadas sin tokens, automatizaciones
  pausadas), API keys e invitaciones revocadas y suscripción cancelada.
- Cada usuario puede poseer como máximo `PLATFORM_MAX_OWNED_ORGANIZATIONS` (5)
  organizaciones creadas desde la app (cada una estrena prueba). Quien se queda sin
  organizaciones ve la pantalla «Crea tu organización».
- Transferir, eliminar o crear organizaciones está bloqueado durante una impersonación.
- Un usuario no puede conceder permisos superiores a los que puede administrar.
- Roles de Organization nunca pueden recibir permisos `platform.*`.
- Impersonación no concede acceso a secretos ni acciones reservadas (docs/09).
