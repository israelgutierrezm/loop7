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

## Asignación de roles
- `OWNER` nunca se asigna ni se invita: se transfiere.
- `ADMIN` sólo lo asignan (o invitan) quienes administran miembros (`members.update`:
  OWNER y ADMIN). Un `MANAGER` con `roles.assign`/`members.invite` asigna de MANAGER hacia
  abajo y no puede cambiar el rol de un ADMIN.
- Nadie cambia su propio rol ni su acceso a marcas.
- `GET /roles` devuelve `assignable`: los roles que el usuario actual puede asignar.

## Reglas críticas
- Solo OWNER puede transferir propiedad.
- OWNER no puede eliminarse sin transferir propiedad.
- Un usuario no puede conceder permisos superiores a los que puede administrar.
- Roles de Organization nunca pueden recibir permisos `platform.*`.
- Impersonación no concede acceso a secretos ni acciones reservadas (docs/09).
