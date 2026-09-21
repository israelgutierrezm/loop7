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
    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';
    public const PAYMENT_GATEWAY_UPDATED = 'payment.gateway_updated';
    public const PAYMENT_WEBHOOK_PROCESSED = 'payment.webhook_processed';
    public const PAYMENT_RECORDED = 'payment.recorded';

    // Social connections
    public const SOCIAL_CONNECTED = 'social.connected';
    public const SOCIAL_DISCONNECTED = 'social.disconnected';
    public const SOCIAL_RECONNECTED = 'social.reconnected';
    public const SOCIAL_TOKEN_REFRESHED = 'social.token_refreshed';
}
