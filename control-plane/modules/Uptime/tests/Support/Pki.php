<?php

namespace Falak\Uptime\Tests\Support;

use RuntimeException;

/**
 * A throwaway PKI for certificate tests: root CA → intermediate CA → leaf, EC P-256 keys (fast).
 */
final class Pki
{
    private string $config = '';

    /** @var array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate} */
    public array $root;

    /** @var array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate} */
    public array $intermediate;

    public string $caFile;

    public function __construct()
    {
        $this->root = $this->issue('Falak Test Root', null, 'v3_ca', 3650);
        $this->intermediate = $this->issue('Falak Test Intermediate', $this->root, 'v3_ca', 3650);
        $this->caFile = (string) tempnam(sys_get_temp_dir(), 'falak-pki-ca-');
        file_put_contents($this->caFile, $this->pem($this->root['cert']));
    }

    private function configFor(string $san): string
    {
        $config = (string) tempnam(sys_get_temp_dir(), 'falak-pki-cnf-');
        file_put_contents($config, <<<CNF
            [req]
            distinguished_name = dn
            [dn]
            [v3_ca]
            basicConstraints = critical, CA:TRUE
            keyUsage = critical, keyCertSign, cRLSign
            subjectKeyIdentifier = hash
            authorityKeyIdentifier = keyid:always
            [v3_leaf]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = serverAuth
            subjectAltName = {$san}
            CNF);

        return $config;
    }

    /**
     * Leaf certificate for $names (first = CN), valid for $days from now.
     *
     * @param  list<string>  $names
     */
    public function leaf(array $names, int $days = 90, bool $selfSigned = false): string
    {
        $this->config = $this->configFor(implode(',', array_map(fn (string $n) => filter_var($n, FILTER_VALIDATE_IP) ? "IP:{$n}" : "DNS:{$n}", $names)));

        try {
            return $this->pem($this->issue($names[0], $selfSigned ? null : $this->intermediate, 'v3_leaf', $days)['cert']);
        } finally {
            @unlink($this->config);
        }
    }

    public function intermediatePem(): string
    {
        return $this->pem($this->intermediate['cert']);
    }

    /**
     * @param  array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate}|null  $issuer
     * @return array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate}
     */
    private function issue(string $cn, ?array $issuer, string $extensions, int $days): array
    {
        $ownConfig = $this->config === '';
        if ($ownConfig) {
            $this->config = $this->configFor('DNS:unused.invalid');
        }

        try {
            return $this->sign($cn, $issuer, $extensions, $days);
        } finally {
            if ($ownConfig) {
                @unlink($this->config);
                $this->config = '';
            }
        }
    }

    /**
     * @param  array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate}|null  $issuer
     * @return array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate}
     */
    private function sign(string $cn, ?array $issuer, string $extensions, int $days): array
    {
        $options = ['config' => $this->config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);
        $csr = $key ? openssl_csr_new(['commonName' => $cn], $key, $options) : false;

        if ($key === false || $csr === false || $csr === true) {
            throw new RuntimeException('openssl: '.openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, $days, $options, random_int(1, PHP_INT_MAX));

        if ($cert === false) {
            throw new RuntimeException('openssl: '.openssl_error_string());
        }

        return ['key' => $key, 'cert' => $cert];
    }

    private function pem(\OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $out);

        return $out;
    }
}
