<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::ensureTestKek();
        self::ensureTestRegistryKey();

        parent::setUp();

        // Feature tests must not depend on a compiled frontend build.
        $this->withoutVite();
    }

    /**
     * The tests' key-encryption key (phpunit.xml: FALAK_KEK_PATH), created once and shared by parallel
     * processes: link() never replaces a file another process created first.
     */
    private static function ensureTestKek(): void
    {
        $path = (string) (getenv('FALAK_KEK_PATH') ?: '');

        if ($path === '' || is_file($path)) {
            return;
        }

        @mkdir(dirname($path), 0700, true);
        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, random_bytes(32));
        chmod($tmp, 0600);
        @link($tmp, $path);
        @unlink($tmp);
    }

    /** The registry token key (phpunit.xml: FALAK_REGISTRY_TOKEN_KEY), shared the same way. */
    private static function ensureTestRegistryKey(): void
    {
        $path = (string) (getenv('FALAK_REGISTRY_TOKEN_KEY') ?: '');

        if ($path === '' || is_file($path)) {
            return;
        }

        @mkdir(dirname($path), 0700, true);
        $tmp = $path.'.'.getmypid().'.tmp';
        openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), $pem);
        file_put_contents($tmp, $pem);
        chmod($tmp, 0600);
        @link($tmp, $path);
        @unlink($tmp);
    }
}
