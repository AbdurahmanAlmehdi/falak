<?php

namespace Falak\Builds\Application;

use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use RuntimeException;

/**
 * The built-in image registry (docker build mode). Images are pushed as
 * <url>/<namespace>/<site-slug>:<build-id> and deployed pinned by digest.
 *
 * Access goes through the token server (Http\Controllers\Internal\RegistryTokenController): the operator credential
 * (auth()) may do anything; agents and builders get credentials() — short-lived, HMAC-signed, naming the
 * repositories and actions they allow — never the operator credential.
 */
final class Registry
{
    /** Lifetime (seconds) of the bearer tokens the token server issues; clients fetch a new one with their credential. */
    public const TOKEN_TTL = 300;

    private const SCOPED_USER = 'falak-scoped';

    public function __construct(private readonly SiteDirectory $sites) {}

    public function url(): string
    {
        return rtrim((string) preg_replace('#^https?://#i', '', (string) config('builds.registry.url')), '/');
    }

    /** The repository name of a site (no host): <namespace>/<slug>; compose services push to <name>/<service>. */
    public function name(string $siteSlug): string
    {
        $namespace = trim((string) config('builds.registry.namespace', 'falak'), '/');

        return ($namespace !== '' ? $namespace.'/' : '').$siteSlug;
    }

    public function repository(string $siteSlug): string
    {
        return $this->url().'/'.$this->name($siteSlug);
    }

    public function image(string $siteSlug, string $buildId): string
    {
        return $this->repository($siteSlug).':'.strtolower($buildId);
    }

    /**
     * The operator credential (falak-ctl, the prune job). Never handed to agents or builders.
     *
     * @return ?array{server: string, username: string, password: string}
     */
    public function auth(): ?array
    {
        $username = (string) config('builds.registry.username');
        $password = (string) config('builds.registry.password');

        if ($username === '' || $password === '') {
            return null;
        }

        return ['server' => $this->url(), 'username' => $username, 'password' => $password];
    }

    /**
     * A credential for the token server allowing $actions on the repositories (each with its sub-repositories) until
     * it expires. $buildId: valid only while that build is assigned or running.
     *
     * @param  list<string>  $repositories  names without the host ({@see name()})
     * @param  list<string>  $actions  pull, push
     * @return ?array{server: string, username: string, password: string} null: no registry, or nothing to allow
     */
    public function credentials(array $repositories, array $actions, int $ttl, ?string $buildId = null): ?array
    {
        if ($this->auth() === null || $repositories === []) {
            return null;
        }

        $body = self::b64((string) json_encode(array_filter([
            'r' => array_values(array_unique($repositories)),
            'a' => array_values($actions),
            'e' => now()->getTimestamp() + $ttl,
            'b' => $buildId,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES));

        return ['server' => $this->url(), 'username' => self::SCOPED_USER, 'password' => $body.'.'.$this->mac($body)];
    }

    /**
     * Pull credentials for the built-in registry's repositories named in $images (image refs or a compose file) that
     * belong to sites of the organization; images of other organizations' sites get nothing.
     *
     * @return ?array{server: string, username: string, password: string}
     */
    public function pullCredentials(string $organizationId, string $images): ?array
    {
        $prefix = $this->url().'/'.$this->name('');

        if ($this->url() === '' || ! str_contains($images, $prefix)) {
            return null;
        }

        preg_match_all('#'.preg_quote($prefix, '#').'([a-z0-9][a-z0-9-]{0,62})(?![a-z0-9-])#', $images, $matches);
        $owned = array_map(fn (SiteData $site) => $site->slug, $this->sites->forOrganization($organizationId));
        $slugs = array_values(array_intersect(array_unique($matches[1]), $owned));

        return $this->credentials(array_map($this->name(...), $slugs), ['pull'], (int) config('builds.registry.credential_ttl', 3600));
    }

    /**
     * What a token-server login may do. null: not a valid (or an expired) credential.
     *
     * @return ?array{repositories: ?list<string>, actions: list<string>, build: ?string} repositories null: any (operator)
     */
    public function grant(string $username, #[\SensitiveParameter] string $password): ?array
    {
        $auth = $this->auth();

        if ($auth === null || $username === '' || $password === '') {
            return null;
        }

        if (hash_equals($auth['username'], $username) && hash_equals($auth['password'], $password)) {
            return ['repositories' => null, 'actions' => ['*'], 'build' => null];
        }

        [$body, $mac] = explode('.', $password, 2) + [1 => ''];

        if ($username !== self::SCOPED_USER || ! hash_equals($this->mac($body), $mac)) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);

        if (! is_array($claims) || (int) ($claims['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return [
            'repositories' => array_values(array_map('strval', (array) ($claims['r'] ?? []))),
            'actions' => array_values(array_map('strval', (array) ($claims['a'] ?? []))),
            'build' => isset($claims['b']) ? (string) $claims['b'] : null,
        ];
    }

    /**
     * The actions of a requested scope the grant allows (the intersection; [] = none).
     *
     * @param  array{repositories: ?list<string>, actions: list<string>}  $grant
     * @param  list<string>  $actions
     * @return list<string>
     */
    public static function allowed(array $grant, string $type, string $name, array $actions): array
    {
        if ($grant['repositories'] === null) {
            return $actions;
        }

        if ($type !== 'repository') {
            return [];
        }

        foreach ($grant['repositories'] as $repository) {
            if ($name === $repository || str_starts_with($name, $repository.'/')) {
                return array_values(array_intersect($actions, $grant['actions']));
            }
        }

        return [];
    }

    /**
     * A registry bearer token (Docker Registry token spec): a JWT signed RS256 with the token key. `kid` is the
     * libtrust key id the registry derives from the certificate in its rootcertbundle.
     *
     * @param  list<array{type: string, name: string, actions: list<string>}>  $access
     *
     * @throws RuntimeException when the token key is missing
     */
    public function token(string $subject, array $access, int $ttl = self::TOKEN_TTL): string
    {
        $path = (string) config('builds.registry.token.key');
        $key = openssl_pkey_get_private((string) @file_get_contents($path));

        if ($key === false) {
            throw new RuntimeException("The registry token key {$path} is missing or unreadable (falak-ctl up creates it).");
        }

        $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) openssl_pkey_get_details($key)['key']));
        $now = now()->getTimestamp();
        $input = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => self::keyId($der)])).'.'.self::b64((string) json_encode([
            'iss' => (string) config('builds.registry.token.issuer', 'falak'),
            'sub' => $subject,
            'aud' => (string) config('builds.registry.token.service', 'falak-registry'),
            'exp' => $now + $ttl,
            'nbf' => $now - 30,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(16)),
            'access' => $access,
        ], JSON_UNESCAPED_SLASHES));

        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64((string) $signature);
    }

    /** libtrust KeyID: base32 of the first 240 bits of SHA-256(DER public key), in groups of four joined by ':'. */
    private static function keyId(string $der): string
    {
        $bits = '';

        foreach (str_split(substr(hash('sha256', $der, true), 0, 30)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $id = '';

        foreach (str_split($bits, 5) as $chunk) {
            $id .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec($chunk)];
        }

        return implode(':', str_split($id, 4));
    }

    private function mac(string $body): string
    {
        return self::b64(hash_hmac('sha256', 'falak-registry-credential:'.$body, (string) config('app.key'), true));
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
