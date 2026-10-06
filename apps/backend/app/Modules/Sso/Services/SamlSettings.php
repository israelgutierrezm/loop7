<?php

declare(strict_types=1);

namespace App\Modules\Sso\Services;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Sso\Models\SsoConnection;
use Illuminate\Support\Carbon;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Settings;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Configuración SAML 2.0 de una organización para onelogin/php-saml. Modo
 * estricto: aserciones firmadas por el IdP (SHA-256, sin algoritmos obsoletos),
 * audiencia, destino y destinatario exactos, y respuestas sólo a peticiones
 * nuestras (nada iniciado por el IdP).
 */
final class SamlSettings
{
    /** Identificador de Loop7 ante el IdP (Entity ID): la URL de sus metadatos. */
    public static function entityId(Organization $organization): string
    {
        return self::base() . '/api/v1/sso/' . $organization->public_id . '/metadata';
    }

    /** Dónde el IdP envía la respuesta (Assertion Consumer Service). */
    public static function acsUrl(Organization $organization): string
    {
        return self::base() . '/api/v1/sso/' . $organization->public_id . '/acs';
    }

    /** Certificados del IdP a la vez (el actual y el siguiente, durante su rotación). */
    public const MAX_CERTIFICATES = 3;

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, SsoConnection $connection): array
    {
        $certificates = self::certificates((string) $connection->idp_certificate);
        $idp = [
            'entityId' => (string) $connection->idp_entity_id,
            'singleSignOnService' => [
                'url' => (string) $connection->idp_sso_url,
                'binding' => Constants::BINDING_HTTP_REDIRECT,
            ],
            'x509cert' => $certificates[0] ?? '',
        ];
        if (count($certificates) > 1) {
            $idp['x509certMulti'] = ['signing' => $certificates];
        }

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => self::entityId($organization),
                'assertionConsumerService' => [
                    'url' => self::acsUrl($organization),
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                // Sin exigir formato: el IdP usa el suyo y el correo sale de los atributos.
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                'x509cert' => '',
                'privateKey' => '',
            ],
            'idp' => $idp,
            'security' => [
                'authnRequestsSigned' => false,
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                'requestedAuthnContext' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,
                'rejectDeprecatedAlgorithm' => true,
                'wantXMLValidation' => true,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ];
    }

    /**
     * Metadatos SAML del SP (sin fecha de caducidad: hay IdP que los importan una vez).
     */
    public function metadata(Organization $organization): string
    {
        $settings = new Settings([
            'strict' => true,
            'sp' => [
                'entityId' => self::entityId($organization),
                'assertionConsumerService' => [
                    'url' => self::acsUrl($organization),
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
            ],
            'security' => ['authnRequestsSigned' => false, 'wantAssertionsSigned' => true],
        ], true);

        return $settings->getSPMetadata(false, null, null, true);
    }

    /**
     * Uno o varios certificados PEM (o un único cuerpo base64 sin cabeceras) →
     * los cuerpos en base64.
     *
     * @return list<string>
     */
    public static function certificates(string $pem): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $matches) > 0) {
            $bodies = $matches[1];
        } else {
            $bodies = [$pem];
        }

        return array_values(array_filter(
            array_map(fn (string $body): string => (string) preg_replace('/\s+/', '', $body), $bodies),
            fn (string $body): bool => $body !== '',
        ));
    }

    /**
     * Datos de cada certificado (null si alguno no es un X.509 legible).
     *
     * @return list<array{subject: string, expires_at: Carbon}>|null
     */
    public static function inspect(string $pem): ?array
    {
        $certificates = self::certificates($pem);
        if ($certificates === [] || count($certificates) > self::MAX_CERTIFICATES) {
            return null;
        }

        $info = [];
        foreach ($certificates as $body) {
            if (base64_decode($body, true) === false) {
                return null;
            }
            $parsed = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----\n");
            if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
                return null;
            }
            $subject = $parsed['subject']['CN'] ?? ($parsed['name'] ?? '');
            $info[] = [
                'subject' => is_array($subject) ? (string) ($subject[0] ?? '') : (string) $subject,
                'expires_at' => Carbon::createFromTimestamp((int) $parsed['validTo_time_t']),
            ];
        }

        return $info;
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
