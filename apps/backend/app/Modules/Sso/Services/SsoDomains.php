<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Dns\DnsResolver;
use App\Modules\Sso\Models\OrganizationDomain;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dominios de correo de una organización. Para usarlo en el inicio de sesión
 * único hay que demostrar que es suyo con un registro TXT; un dominio verificado
 * sólo puede pertenecer a una organización. Los de correo gratuito no se pueden
 * reclamar (darían el acceso de cualquier usuario de Gmail, por ejemplo).
 */
final class SsoDomains
{
    public const MAX_DOMAINS = 10;

    /** Proveedores de correo gratuito y de uso masivo. */
    private const FREE_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.es',
        'yahoo.com.mx', 'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com',
        'gmx.com', 'gmx.net', 'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'tutanota.com', 'fastmail.com',
        'hotmail.es', 'outlook.es', 'live.com.mx', 'prodigy.net.mx', 'qq.com', '163.com',
    ];

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * «empresa.com», «https://www.empresa.com/…» o «ana@empresa.com» → «empresa.com».
     */
    public static function normalize(string $input): ?string
    {
        $value = mb_strtolower(trim($input));
        if (str_contains($value, '@')) {
            $value = (string) Str::afterLast($value, '@');
        }
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = (string) Str::before($value, '/');
        $value = (string) preg_replace('/^www\./', '', rtrim($value, '.'));

        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) === 1 ? $value : null;
    }

    public function add(Organization $organization, string $input): OrganizationDomain
    {
        $domain = self::normalize($input);
        if ($domain === null) {
            throw ValidationException::withMessages(['domain' => 'Escribe un dominio válido, por ejemplo empresa.com.']);
        }
        if (in_array($domain, self::FREE_PROVIDERS, true)) {
            throw ValidationException::withMessages(['domain' => 'No se pueden usar dominios de correo gratuito.']);
        }
        if ($this->verifiedElsewhere($domain, $organization->id)) {
            throw ValidationException::withMessages(['domain' => 'Ese dominio ya está verificado por otra organización.']);
        }

        $existing = OrganizationDomain::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id);
        if ((clone $existing)->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Ya añadiste ese dominio.']);
        }
        if ($existing->count() >= self::MAX_DOMAINS) {
            throw ValidationException::withMessages(['domain' => 'Puedes añadir hasta ' . self::MAX_DOMAINS . ' dominios.']);
        }

        $record = OrganizationDomain::query()->create([
            'organization_id' => $organization->id,
            'domain' => $domain,
            'verification_token' => Str::lower(Str::random(40)),
        ]);
        $this->audit->log(AuditAction::SSO_DOMAIN_ADDED, $record, ['domain' => $domain], organizationId: $organization->id);

        return $record;
    }

    /**
     * Busca el registro TXT. Devuelve si quedó verificado.
     */
    public function verify(OrganizationDomain $domain): bool
    {
        if ($domain->isVerified()) {
            return true;
        }
        if ($this->verifiedElsewhere($domain->domain, $domain->organization_id)) {
            throw ValidationException::withMessages(['domain' => 'Ese dominio ya está verificado por otra organización.']);
        }

        $found = in_array($domain->txtRecord(), array_map('trim', $this->dns->txtRecords($domain->domain)), true);
        if (! $found) {
            $domain->forceFill(['last_checked_at' => now()])->save();

            return false;
        }

        try {
            $domain->forceFill(['last_checked_at' => now(), 'verified_at' => now(), 'verified_domain' => $domain->domain])->save();
        } catch (UniqueConstraintViolationException) {
            // Otra organización lo verificó a la vez: decide el índice único.
            throw ValidationException::withMessages(['domain' => 'Ese dominio ya está verificado por otra organización.']);
        }
        $this->audit->log(AuditAction::SSO_DOMAIN_VERIFIED, $domain, ['domain' => $domain->domain], organizationId: $domain->organization_id);

        return true;
    }

    public function remove(OrganizationDomain $domain): void
    {
        $name = $domain->domain;
        $organizationId = $domain->organization_id;
        $domain->delete();

        $this->audit->log(AuditAction::SSO_DOMAIN_REMOVED, null, ['domain' => $name], organizationId: $organizationId);
    }

    /**
     * Organización (con SSO) dueña del dominio verificado del correo.
     */
    public function ownerOf(string $email): ?OrganizationDomain
    {
        $domain = self::normalize((string) Str::afterLast(mb_strtolower(trim($email)), '@'));
        if ($domain === null) {
            return null;
        }

        return OrganizationDomain::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('verified_domain', $domain)
            ->first();
    }

    /**
     * ¿El correo es de un dominio verificado de la organización?
     */
    public function belongsTo(string $email, int $organizationId): bool
    {
        return $this->ownerOf($email)?->organization_id === $organizationId;
    }

    private function verifiedElsewhere(string $domain, int $organizationId): bool
    {
        return OrganizationDomain::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('verified_domain', $domain)
            ->where('organization_id', '!=', $organizationId)
            ->exists();
    }
}
