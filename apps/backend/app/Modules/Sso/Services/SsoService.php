<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Data\SamlIdentity;
use App\Modules\Sso\Exceptions\SsoException;
use App\Modules\Sso\Models\SsoConnection;
use App\Support\Tenancy\OrganizationScope;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * Inicio de sesión único SAML 2.0 iniciado por Loop7 (docs/03):
 *
 *  1. `start`: petición al IdP con un RelayState aleatorio de un solo uso que
 *     guarda el ID de la petición (InResponseTo) y, en el inicio de sesión, el
 *     hash de un verificador que sólo conoce el navegador que lo pidió.
 *  2. `consume` (ACS): valida la respuesta firmada en modo estricto, impide
 *     repetir una aserción, exige un dominio verificado de la organización y
 *     emite un código de un solo uso (60 s) para el SPA.
 *  3. `exchange`: el SPA canjea código + verificador y obtiene la sesión.
 *
 * No se aceptan respuestas iniciadas por el IdP ni se usa la sesión en el ACS
 * (el POST entre sitios no lleva las cookies).
 */
final class SsoService
{
    public const RELAY_TTL = 600;
    public const CODE_TTL = 60;
    public const TEST_TTL = 600;

    /** Atributos habituales del correo (Entra ID, Okta, Google, ADFS, OIDs LDAP). */
    private const EMAIL_CLAIMS = [
        'email', 'mail', 'emailaddress', 'Email', 'EmailAddress', 'user.email', 'User.Email',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'urn:oid:0.9.2342.19200300.100.1.3',
    ];

    /** Atributos habituales del nombre visible. */
    private const NAME_CLAIMS = [
        'displayName', 'displayname', 'name', 'fullName', 'cn',
        'http://schemas.microsoft.com/identity/claims/displayname',
        'urn:oid:2.16.840.1.113730.3.1.241',
    ];

    private const GIVEN_NAME_CLAIMS = [
        'givenName', 'firstName', 'first_name', 'given_name',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'urn:oid:2.5.4.42',
    ];

    private const SURNAME_CLAIMS = [
        'sn', 'surname', 'lastName', 'last_name', 'family_name',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'urn:oid:2.5.4.4',
    ];

    public function __construct(
        private readonly SamlSettings $settings,
        private readonly SsoDomains $domains,
        private readonly SsoProvisioner $provisioner,
        private readonly EntitlementsService $entitlements,
        private readonly AuditLogger $audit,
    ) {
    }

    public function connectionOf(Organization $organization): ?SsoConnection
    {
        return SsoConnection::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->first();
    }

    /**
     * Conexión que atiende ese correo: la de la organización dueña de su dominio
     * verificado, si está lista para iniciar sesión.
     */
    public function forEmail(string $email): ?SsoConnection
    {
        $domain = $this->domains->ownerOf($email);
        $organization = $domain !== null ? Organization::query()->find($domain->organization_id) : null;
        if ($organization === null) {
            return null;
        }
        $connection = $this->connectionOf($organization);

        return $connection !== null && $this->usable($organization, $connection) ? $connection : null;
    }

    /**
     * ¿Se puede entrar con ella? La prueba de conexión no exige que esté activada.
     */
    public function usable(Organization $organization, SsoConnection $connection, bool $test = false): bool
    {
        return ($test || $connection->is_enabled)
            && $connection->isConfigured()
            && ! $organization->isSuspended()
            && $this->entitlements->allows($organization, Entitlement::FEATURE_SSO);
    }

    /**
     * URL del IdP con la petición SAML (HTTP-Redirect).
     *
     * @param  string|null  $challenge  SHA-256 (hex) del verificador del navegador; null en la prueba
     */
    public function start(Organization $organization, SsoConnection $connection, ?string $challenge, ?User $tester = null): string
    {
        $auth = new Auth($this->settings->for($organization, $connection));
        // El estándar limita RelayState a 80 bytes.
        $relay = Str::random(40);
        $url = (string) $auth->login($relay, [], false, false, true, false);

        Cache::put($this->relayKey($relay), [
            'organization_id' => $organization->id,
            'request_id' => (string) $auth->getLastRequestID(),
            'challenge' => $challenge,
            'tester_id' => $tester?->id,
        ], self::RELAY_TTL);

        return $url;
    }

    /**
     * Respuesta del IdP en el ACS. Devuelve a qué URL del SPA llevar al navegador.
     */
    public function consume(Organization $organization, string $samlResponse, string $relayState): string
    {
        $relay = $relayState !== '' && strlen($relayState) <= 80 ? Cache::pull($this->relayKey($relayState)) : null;
        if (! is_array($relay) || $relay['organization_id'] !== $organization->id) {
            // Sin petición nuestra: caducada, ya usada, de otra organización o iniciada por el IdP.
            $this->audit->log(AuditAction::SSO_LOGIN_FAILED, $organization, ['reason' => SsoException::EXPIRED], organizationId: $organization->id);

            return $this->loginError(SsoException::EXPIRED);
        }

        $testerId = is_int($relay['tester_id']) ? $relay['tester_id'] : null;
        $identity = null;

        try {
            $connection = $this->connectionOf($organization);
            if ($connection === null || ! $this->usable($organization, $connection, $testerId !== null)) {
                throw new SsoException(SsoException::NOT_ENABLED);
            }

            $identity = $this->validate($organization, $connection, $samlResponse, (string) $relay['request_id']);
            if (! $this->domains->belongsTo($identity->email, $organization->id)) {
                throw new SsoException(SsoException::DOMAIN_NOT_VERIFIED, 'El dominio del correo no es un dominio verificado de la organización.');
            }

            if ($testerId !== null) {
                return $this->testResult($organization, $testerId, [
                    'ok' => true,
                    'outcome' => $this->provisioner->assess($organization, $connection, $identity->email),
                ], $identity);
            }

            $user = $this->provisioner->resolve($organization, $connection, $identity->email, $identity->name);
            $connection->forceFill(['last_login_at' => now()])->save();

            $code = Str::random(64);
            Cache::put($this->codeKey($code), [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'challenge' => $relay['challenge'],
            ], self::CODE_TTL);

            // En el fragmento: no viaja al servidor ni en el Referer.
            return $this->frontend('/sso/callback#code=' . $code);
        } catch (SsoException $e) {
            if ($testerId !== null) {
                return $this->testResult($organization, $testerId, [
                    'ok' => false,
                    'reason' => $e->reason,
                    'detail' => Str::limit($e->getMessage(), 500),
                ], $identity);
            }

            $this->audit->log(AuditAction::SSO_LOGIN_FAILED, $organization, array_filter([
                'reason' => $e->reason,
                'detail' => Str::limit($e->getMessage(), 500),
                'email' => $identity?->email,
            ]), organizationId: $organization->id);

            return $this->loginError($e->reason);
        }
    }

    /**
     * Canjea el código del ACS por la persona y la organización con que entró.
     *
     * @return array{0: User, 1: Organization}
     *
     * @throws SsoException
     */
    public function exchange(string $code, string $verifier): array
    {
        $key = $this->codeKey($code);
        // Un solo uso, también ante dos canjes simultáneos (add es atómico).
        if (! Cache::has($key) || ! Cache::add($key . ':used', true, self::CODE_TTL * 2)) {
            throw new SsoException(SsoException::EXPIRED);
        }
        $payload = Cache::pull($key);
        if (! is_array($payload) || ! is_string($payload['challenge']) || ! hash_equals($payload['challenge'], hash('sha256', $verifier))) {
            throw new SsoException(SsoException::EXPIRED);
        }

        $user = User::query()->find($payload['user_id']);
        $organization = Organization::query()->find($payload['organization_id']);
        if ($user === null || $organization === null || $user->isBlocked() || $user->isPlatformAdmin()) {
            throw new SsoException(SsoException::BLOCKED);
        }
        $active = $organization->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', MembershipStatus::ACTIVE->value)
            ->exists();
        if (! $active) {
            throw new SsoException(SsoException::SUSPENDED);
        }

        return [$user, $organization];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTestResult(Organization $organization, User $user, string $token): ?array
    {
        $data = Cache::get($this->testKey($token));
        if (! is_array($data) || $data['tester_id'] !== $user->id || $data['organization_id'] !== $organization->id) {
            return null;
        }

        return $data['result'];
    }

    /**
     * Guarda el resultado de una prueba de conexión (sólo lo verá quien la hizo,
     * en su organización) y devuelve la URL del SPA donde mostrarlo.
     *
     * @param  array<string, mixed>  $result
     */
    private function testResult(Organization $organization, int $testerId, array $result, ?SamlIdentity $identity): string
    {
        $token = Str::random(40);
        $result += [
            'email' => $identity?->email,
            'name' => $identity?->name,
            'name_id' => $identity?->nameId,
            'attributes' => $identity->attributes ?? [],
        ];
        Cache::put($this->testKey($token), [
            'tester_id' => $testerId,
            'organization_id' => $organization->id,
            'result' => $result,
        ], self::TEST_TTL);

        $this->audit->log(
            AuditAction::SSO_TESTED,
            $organization,
            array_filter(['ok' => $result['ok'], 'reason' => $result['reason'] ?? null], fn ($v) => $v !== null),
            actor: User::query()->find($testerId),
            organizationId: $organization->id,
        );

        return $this->frontend('/app/settings#sso_test=' . $token);
    }

    /**
     * Valida la respuesta (firma, emisor, audiencia, destino, tiempos, InResponseTo)
     * y extrae la identidad.
     *
     * @throws SsoException
     */
    private function validate(Organization $organization, SsoConnection $connection, string $samlResponse, string $requestId): SamlIdentity
    {
        try {
            $settings = new Settings($this->settings->for($organization, $connection));
            $response = $this->atAcs(SamlSettings::acsUrl($organization), function () use ($settings, $samlResponse, $requestId): Response {
                $response = new Response($settings, $samlResponse);
                if (! $response->isValid($requestId)) {
                    throw new SsoException(SsoException::INVALID_RESPONSE, (string) $response->getError());
                }

                return $response;
            });
            $assertionId = (string) $response->getAssertionId();
            $notOnOrAfter = $response->getAssertionNotOnOrAfter();
            $attributes = $response->getAttributes();
            $friendly = $response->getAttributesWithFriendlyName();
            $nameId = (string) $response->getNameId();
        } catch (SsoException $e) {
            throw $e;
        } catch (Throwable $e) {
            // XML mal formado, DOCTYPE (XXE), base64 inválido…
            throw new SsoException(SsoException::INVALID_RESPONSE, $e->getMessage());
        }

        // Anti-repetición: cada aserción se acepta una vez mientras sigue vigente
        // (sin NotOnOrAfter en la confirmación del sujeto, una hora).
        $ttl = $notOnOrAfter > 0 ? $notOnOrAfter - time() + Constants::ALLOWED_CLOCK_DRIFT : 3600;
        $replayKey = 'sso:assertion:' . $organization->id . ':' . hash('sha256', $assertionId);
        if ($assertionId === '' || ! Cache::add($replayKey, true, max(60, min($ttl, 86400)))) {
            throw new SsoException(SsoException::INVALID_RESPONSE, 'La aserción ya se había usado.');
        }

        $all = $attributes + $friendly;
        $email = $connection->email_attribute !== null && $connection->email_attribute !== ''
            ? $this->firstValue($all, [$connection->email_attribute])
            : ($this->firstValue($all, self::EMAIL_CLAIMS) ?? $nameId);
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SsoException(SsoException::NO_EMAIL, 'La respuesta no trae un correo válido.');
        }

        $name = $connection->name_attribute !== null && $connection->name_attribute !== ''
            ? $this->firstValue($all, [$connection->name_attribute])
            : $this->firstValue($all, self::NAME_CLAIMS);
        if ($name === null) {
            $full = trim(($this->firstValue($all, self::GIVEN_NAME_CLAIMS) ?? '') . ' ' . ($this->firstValue($all, self::SURNAME_CLAIMS) ?? ''));
            $name = $full !== '' ? $full : null;
        }

        return new SamlIdentity($email, $name, Str::limit($nameId, 255), $this->summary($all));
    }

    /**
     * onelogin calcula la URL «actual» con las variables del servidor (para el
     * destino y el destinatario). Se fija a la URL canónica del ACS mientras se
     * valida y se restaura después: no depende de proxies ni cabeceras Host.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atAcs(string $acsUrl, Closure $callback): mixed
    {
        $parts = parse_url($acsUrl);
        $parts = is_array($parts) ? $parts : [];
        $https = ($parts['scheme'] ?? 'http') === 'https';
        $previous = [
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
            'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
        ];

        Utils::setSelfProtocol($https ? 'https' : 'http');
        Utils::setSelfHost($parts['host'] ?? 'localhost');
        Utils::setSelfPort($parts['port'] ?? ($https ? 443 : 80));
        Utils::setBaseURLPath('');
        $_SERVER['REQUEST_URI'] = $parts['path'] ?? '/';
        unset($_SERVER['QUERY_STRING']);

        try {
            return $callback();
        } finally {
            // Cadena vacía: onelogin vuelve a calcularla con las variables del servidor.
            Utils::setBaseURL('');
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $names
     */
    private function firstValue(array $attributes, array $names): ?string
    {
        foreach ($names as $name) {
            $values = $attributes[$name] ?? null;
            $value = is_array($values) ? ($values[0] ?? null) : $values;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Atributos recibidos (nombre → primer valor, recortados) para la prueba.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private function summary(array $attributes): array
    {
        $summary = [];
        foreach (array_slice($attributes, 0, 30, true) as $name => $values) {
            $value = is_array($values) ? ($values[0] ?? '') : $values;
            $summary[Str::limit((string) $name, 200)] = Str::limit(is_scalar($value) ? (string) $value : '', 200);
        }

        return $summary;
    }

    private function loginError(string $reason): string
    {
        return $this->frontend('/login?sso_error=' . rawurlencode($reason));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }

    private function relayKey(string $relay): string
    {
        return 'sso:relay:' . hash('sha256', $relay);
    }

    private function codeKey(string $code): string
    {
        return 'sso:code:' . hash('sha256', $code);
    }

    private function testKey(string $token): string
    {
        return 'sso:test:' . hash('sha256', $token);
    }
}
