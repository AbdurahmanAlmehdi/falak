<?php

namespace Falak\Terminal\Infrastructure;

use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Support\Str;

/**
 * Tokens the terminal relay accepts (docs/plans/CONSOLE.md "Tokens"): b64url(payload) "." b64url(HMAC-SHA256), keyed by
 * HMAC-SHA256(APP_KEY bytes, "falak-terminal-relay/v1"). The relay derives the same key from the same APP_KEY.
 */
final class RelayTokens
{
    public const KEY_CONTEXT = 'falak-terminal-relay/v1';

    public function __construct(private readonly ?string $appKey = null) {}

    public static function enabled(): bool
    {
        return (bool) config('terminal.relay.enabled', false);
    }

    /** The relay's client base URL: FALAK_TERMINAL_RELAY_URL, else APP_URL with ws(s):// plus /terminal/v1. */
    public static function baseUrl(): string
    {
        $url = config('terminal.relay.url');

        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        return (string) preg_replace('#^http(s?)://#i', 'ws$1://', rtrim((string) config('app.url'), '/')).'/terminal/v1';
    }

    /**
     * What the agent dials the relay with: valid for the session's whole life, reusable.
     *
     * When the agent's certificate fingerprint is known (SHA-256 of the DER, lowercase hex) it is
     * bound into the token as `fp`; the relay only accepts the token from a connection presenting that
     * exact certificate. When no fingerprint is available the claim is omitted — the relay rejects
     * fp-less tokens, so the relay session will fail at connect time rather than at session creation.
     *
     * @param  string|null  $fingerprint  SHA-256 of the agent's client certificate (lowercase hex), or null when unknown
     */
    public function agentToken(TerminalSession $session, ?string $fingerprint = null): string
    {
        $lifetime = (int) config('terminal.max_duration', 3600) + (int) config('terminal.relay.agent_token_grace', 300);

        $payload = [
            'v' => 1,
            'sid' => $session->id,
            'aud' => 'agent',
            'sub' => $session->server_id,
            'w' => true,
            'exp' => now()->getTimestamp() + $lifetime,
            'n' => Str::random(22),
        ];

        if ($fingerprint !== null && $fingerprint !== '') {
            $payload['fp'] = strtolower($fingerprint);
        }

        return $this->sign($payload);
    }

    /** A browser's or CLI's single-use ticket to connect within ticket_ttl seconds. */
    public function clientTicket(TerminalSession $session, string $userId, bool $writable): string
    {
        return $this->sign([
            'v' => 1,
            'sid' => $session->id,
            'aud' => 'client',
            'sub' => $userId,
            'w' => $writable,
            'exp' => now()->getTimestamp() + (int) config('terminal.relay.ticket_ttl', 60),
            'n' => Str::random(22),
        ]);
    }

    /**
     * The control plane's call to the relay's internal API (kick): valid for 60 s. With $keep, clients whose ticket was
     * issued to that user stay connected; without, all are dropped.
     */
    public function controlToken(TerminalSession $session, ?string $keep = null): string
    {
        return $this->sign(array_filter([
            'v' => 1,
            'sid' => $session->id,
            'aud' => 'control',
            'exp' => now()->getTimestamp() + 60,
            'n' => Str::random(22),
            'keep' => $keep,
        ], fn ($value) => $value !== null));
    }

    /** `<relay url>/sessions/<id>?ticket=…` for a client. */
    public function clientUrl(TerminalSession $session, string $userId, bool $writable): string
    {
        return self::baseUrl().'/sessions/'.$session->id.'?ticket='.$this->clientTicket($session, $userId, $writable);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return $this->signJson((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Sign exactly these payload bytes (the relay verifies the encoded bytes, so key order is free). */
    public function signJson(string $json): string
    {
        $body = self::b64url($json);

        return $body.'.'.self::b64url(hash_hmac('sha256', $body, $this->key(), true));
    }

    /** The raw 32-byte signing key. */
    public function key(): string
    {
        $appKey = $this->appKey ?? (string) config('app.key');
        $bytes = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

        return hash_hmac('sha256', self::KEY_CONTEXT, $bytes === false ? $appKey : $bytes, true);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
