# Esquema base de datos

Este listado es conceptual. Claude debe crear migraciones Laravel, índices, FKs, uniques y tipos adecuados.

## Identity
- users
- user_sessions (si se requiere capa adicional)
- personal_access_tokens
- password_reset_tokens
- mfa_methods / recovery_codes

## Organizations / Access
- organizations
- organization_users
- organization_invitations
- brand_user_access
- roles
- permissions
- model_has_roles
- model_has_permissions
- role_has_permissions

## Brands
- brands
- brand_guidelines
- brand_audiences
- brand_products
- brand_services
- brand_documents
- brand_knowledge_items

## Social
- social_providers
- social_connections
- social_connection_destinations
- social_token_events
- social_capabilities

## Content
- content_items
- post_variants
- post_variant_media
- publication_targets
- publication_attempts
- content_comments
- approval_requests
- approval_actions

## Campaigns / Calendar
- campaigns
- campaign_content
- schedules
- content_categories

## Media
- media_assets
- media_folders
- media_tags
- media_asset_tags

## AI
- ai_providers
- ai_models
- organization_ai_credentials
- ai_requests
- ai_usage
- ai_credit_ledger

## Analytics
- account_metric_snapshots
- post_metric_snapshots
- analytics_sync_runs

## Inbox
- inbox_conversations
- inbox_messages
- inbox_assignments
- inbox_labels
- inbox_conversation_labels

## Automation
- automation_workflows
- automation_triggers
- automation_conditions
- automation_actions
- automation_runs

## Billing
- plans
- plan_prices
- entitlements
- plan_entitlements
- subscriptions
- subscription_items
- usage_counters
- add_ons
- organization_add_ons

## Payments
- payment_gateways
- payment_gateway_credentials
- provider_product_mappings
- payment_transactions
- payment_webhook_events
- invoices

## Platform / Ops
- feature_flags
- audit_logs
- integration_logs
- webhook_deliveries
- notifications
- system_settings

## Campos transversales
Usar ULID/UUID público donde convenga, timestamps, soft deletes solo donde tenga sentido, `organization_id` indexado en tablas tenant-owned y claves compuestas para evitar duplicados lógicos.
