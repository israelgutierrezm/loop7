<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

/**
 * Códigos estables de acciones auditables (docs/03: auditoría mínima).
 */
final class AuditAction
{
    // Auth
    public const AUTH_REGISTERED = 'auth.registered';
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGIN_FAILED = 'auth.login_failed';
    public const AUTH_LOGOUT = 'auth.logout';
    public const AUTH_EMAIL_VERIFIED = 'auth.email_verified';
    public const AUTH_PASSWORD_RESET = 'auth.password_reset';
    public const AUTH_PASSWORD_CHANGED = 'auth.password_changed';

    // MFA
    public const MFA_ENABLED = 'mfa.enabled';
    public const MFA_DISABLED = 'mfa.disabled';
    public const MFA_CHALLENGE_PASSED = 'mfa.challenge_passed';
    public const MFA_CHALLENGE_FAILED = 'mfa.challenge_failed';

    // Organizations / members
    public const ORGANIZATION_CREATED = 'organization.created';
    public const ORGANIZATION_UPDATED = 'organization.updated';
    public const ORGANIZATION_DELETED = 'organization.deleted';
    public const ORGANIZATION_OWNERSHIP_TRANSFERRED = 'organization.ownership_transferred';
    public const MEMBER_INVITED = 'member.invited';
    public const MEMBER_INVITATION_REVOKED = 'member.invitation_revoked';
    public const MEMBER_JOINED = 'member.joined';
    public const MEMBER_REMOVED = 'member.removed';
    public const MEMBER_ROLE_ASSIGNED = 'member.role_assigned';
    public const MEMBER_ROLE_REVOKED = 'member.role_revoked';

    // Brands
    public const BRAND_CREATED = 'brand.created';
    public const BRAND_UPDATED = 'brand.updated';
    public const BRAND_DELETED = 'brand.deleted';
    public const BRAND_ACCESS_GRANTED = 'brand.access_granted';
    public const BRAND_ACCESS_REVOKED = 'brand.access_revoked';

    // Platform / SUPERADMIN
    public const SUPERADMIN_IMPERSONATION_STARTED = 'superadmin.impersonation_started';
    public const SUPERADMIN_IMPERSONATION_ENDED = 'superadmin.impersonation_ended';
    public const SUPERADMIN_ORGANIZATION_SUSPENDED = 'superadmin.organization_suspended';

    // Billing / Payments
    public const SUBSCRIPTION_TRIAL_STARTED = 'subscription.trial_started';
    public const SUBSCRIPTION_CHANGED = 'subscription.changed';
    public const SUBSCRIPTION_RENEWED = 'subscription.renewed';
    public const SUBSCRIPTION_PAYMENT_FAILED = 'subscription.payment_failed';
    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';
    public const SUBSCRIPTION_RESUMED = 'subscription.resumed';
    public const SUBSCRIPTION_EXPIRED = 'subscription.expired';
    public const SUBSCRIPTION_SUSPENDED = 'subscription.suspended';
    public const CHECKOUT_STARTED = 'billing.checkout_started';
    public const INVOICE_VOIDED = 'billing.invoice_voided';
    public const PLAN_SAVED = 'billing.plan_saved';
    public const PLAN_DELETED = 'billing.plan_deleted';
    public const ENTITLEMENT_OVERRIDE_SAVED = 'billing.entitlement_override_saved';
    public const ADD_ON_ASSIGNED = 'billing.add_on_assigned';
    public const PAYMENT_GATEWAY_UPDATED = 'payment.gateway_updated';
    public const PAYMENT_WEBHOOK_PROCESSED = 'payment.webhook_processed';
    public const PAYMENT_RECORDED = 'payment.recorded';
    public const PLATFORM_SETTINGS_UPDATED = 'platform.settings_updated';

    // Content / Campaigns
    public const CONTENT_CREATED = 'content.created';
    public const CONTENT_UPDATED = 'content.updated';
    public const CONTENT_DELETED = 'content.deleted';
    public const CONTENT_SUBMITTED = 'content.submitted';
    public const CONTENT_APPROVED = 'content.approved';
    public const CONTENT_CHANGES_REQUESTED = 'content.changes_requested';
    public const CONTENT_SCHEDULED = 'content.scheduled';
    public const CONTENT_PUBLISHING = 'content.publishing';
    public const CONTENT_PUBLISHED = 'content.published';
    public const CONTENT_PUBLISH_FAILED = 'content.publish_failed';
    public const CAMPAIGN_CREATED = 'campaign.created';

    // Social connections
    public const SOCIAL_CONNECTED = 'social.connected';
    public const SOCIAL_DISCONNECTED = 'social.disconnected';
    public const SOCIAL_RECONNECTED = 'social.reconnected';
    public const SOCIAL_TOKEN_REFRESHED = 'social.token_refreshed';
    public const SOCIAL_TOKEN_EXPIRED = 'social.token_expired';
    public const SOCIAL_PROVIDER_UPDATED = 'social.provider_updated';

    // API pública
    public const API_KEY_CREATED = 'api.key_created';
    public const API_KEY_REVOKED = 'api.key_revoked';

    // Inbox
    public const INBOX_REPLIED = 'inbox.replied';
    public const INBOX_NOTE_ADDED = 'inbox.note_added';
    public const INBOX_ASSIGNED = 'inbox.assigned';
    public const INBOX_STATUS_CHANGED = 'inbox.status_changed';

    // IA
    public const AI_TEXT_GENERATED = 'ai.text_generated';
    public const AI_IMAGE_GENERATED = 'ai.image_generated';
    public const AI_GENERATION_FAILED = 'ai.generation_failed';
    public const AI_KEY_UPDATED = 'ai.key_updated';
    public const AI_KEY_REMOVED = 'ai.key_removed';
    public const AI_PROVIDER_UPDATED = 'ai.provider_updated';
}
