<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * Proveedor de identidad SAML de prueba: clave y certificado propios (creados al
 * vuelo, nada se guarda en el repositorio) y respuestas con la aserción firmada.
 */
final class SamlIdp
{
    public const ENTITY_ID = 'https://idp.example.test/metadata';
    public const SSO_URL = 'https://idp.example.test/sso';

    /** @var array<string, array{key: string, cert: string}> */
    private static array $pairs = [];

    private string $key;

    private string $cert;

    public function __construct(string $name = 'idp')
    {
        self::$pairs[$name] ??= self::generate($name);
        $this->key = self::$pairs[$name]['key'];
        $this->cert = self::$pairs[$name]['cert'];
    }

    /**
     * IdP con la clave guardada en una carpeta temporal (las pruebas E2E la
     * comparten entre procesos de Artisan); la crea la primera vez.
     */
    public static function stored(string $directory): self
    {
        $name = 'stored:' . $directory;
        if (! isset(self::$pairs[$name])) {
            $keyPath = $directory . '/idp.key';
            $certPath = $directory . '/idp.crt';
            if (! is_file($keyPath) || ! is_file($certPath)) {
                $pair = self::generate('idp');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($keyPath, $pair['key']);
                file_put_contents($certPath, $pair['cert']);
            }
            self::$pairs[$name] = ['key' => (string) file_get_contents($keyPath), 'cert' => (string) file_get_contents($certPath)];
        }

        return new self($name);
    }

    public function certificate(): string
    {
        return $this->cert;
    }

    /**
     * Petición SAML (HTTP-Redirect) → su ID, para responder con InResponseTo.
     */
    public static function requestIdFrom(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $xml = gzinflate((string) base64_decode((string) ($query['SAMLRequest'] ?? ''), true));
        if ($xml === false || preg_match('/\sID="([^"]+)"/', $xml, $m) !== 1) {
            throw new RuntimeException('La URL no trae una petición SAML.');
        }

        return $m[1];
    }

    public static function relayStateFrom(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);

        return (string) ($query['RelayState'] ?? '');
    }

    /**
     * Respuesta SAML codificada en base64 (lo que el navegador envía al ACS).
     *
     * @param  array<string, string>  $attributes
     * @param  array{issuer?: string, audience?: string, destination?: string, assertion_id?: string, ttl?: int, sign?: bool}  $options
     */
    public function response(string $acsUrl, string $spEntityId, string $requestId, string $nameId, array $attributes = [], array $options = []): string
    {
        $now = time();
        $issuer = htmlspecialchars($options['issuer'] ?? self::ENTITY_ID, ENT_XML1);
        $audience = htmlspecialchars($options['audience'] ?? $spEntityId, ENT_XML1);
        $destination = htmlspecialchars($options['destination'] ?? $acsUrl, ENT_XML1);
        $recipient = htmlspecialchars($acsUrl, ENT_XML1);
        $assertionId = $options['assertion_id'] ?? '_a' . bin2hex(random_bytes(16));
        $responseId = '_r' . bin2hex(random_bytes(16));
        $issueInstant = self::time($now);
        $notBefore = self::time($now - 60);
        $notOnOrAfter = self::time($now + ($options['ttl'] ?? 300));
        $nameIdXml = htmlspecialchars($nameId, ENT_XML1);

        $attributeXml = '';
        foreach ($attributes as $name => $value) {
            $attributeXml .= '<saml:Attribute Name="' . htmlspecialchars($name, ENT_XML1) . '" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic">'
                . '<saml:AttributeValue>' . htmlspecialchars($value, ENT_XML1) . '</saml:AttributeValue></saml:Attribute>';
        }
        $statement = $attributeXml !== '' ? '<saml:AttributeStatement>' . $attributeXml . '</saml:AttributeStatement>' : '';

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$destination}" InResponseTo="{$requestId}"><saml:Issuer>{$issuer}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status><saml:Assertion ID="{$assertionId}" Version="2.0" IssueInstant="{$issueInstant}"><saml:Issuer>{$issuer}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$nameIdXml}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$recipient}" InResponseTo="{$requestId}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$issueInstant}" SessionIndex="_s{$responseId}"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>{$statement}</saml:Assertion></samlp:Response>
XML;

        if (($options['sign'] ?? true) === false) {
            return base64_encode($xml);
        }

        return base64_encode($this->signAssertion($xml));
    }

    /**
     * Firma la aserción (enveloped, exc-c14n, RSA-SHA256) justo tras su Issuer,
     * como hacen Entra ID, Okta o Google.
     */
    private function signAssertion(string $xml): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        /** @var DOMElement $assertion */
        $assertion = $dom->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);
        $issuer = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->key);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $assertion,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );
        $dsig->sign($key);
        $dsig->add509Cert($this->cert);
        $dsig->insertSignature($assertion, $issuer?->nextSibling);

        return (string) $dom->saveXML($dom->documentElement);
    }

    private static function time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * @return array{key: string, cert: string}
     */
    private static function generate(string $name): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        // En Windows OpenSSL necesita su archivo de configuración (PHP lo trae en extras/ssl).
        $config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (getenv('OPENSSL_CONF') === false && is_file($config)) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('OpenSSL no pudo crear la clave de prueba.');
        }
        $csr = openssl_csr_new(['commonName' => $name . '.example.test'], $key, $options);
        $cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 30, $options) : false;
        if ($cert === false || ! openssl_x509_export($cert, $certPem) || ! openssl_pkey_export($key, $keyPem, null, $options)) {
            throw new RuntimeException('OpenSSL no pudo crear el certificado de prueba.');
        }

        return ['key' => $keyPem, 'cert' => $certPem];
    }
}
