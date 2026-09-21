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

## Reglas críticas
- Solo OWNER puede transferir propiedad.
- OWNER no puede eliminarse sin transferir propiedad.
- Un usuario no puede conceder permisos superiores a los que puede administrar.
- Roles de Organization nunca pueden recibir permisos `platform.*`.
- Impersonación no concede acceso a secretos ni acciones reservadas.
