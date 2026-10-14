<?php

namespace Falak\Terminal\Infrastructure;

use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Revokes relay access (docs/plans/CONSOLE.md): `POST <internal url>/internal/v1/sessions/<sid>/kick` drops a session's
 * clients — all of them, or all but $keep's. Best effort: a relay that is down or slow never fails what triggered it.
 */
final class RelayKicker
{
    public function __construct(private readonly RelayTokens $tokens) {}

    public function kick(TerminalSession $session, ?string $keep = null): void
    {
        if (! RelayTokens::enabled() || ! $session->isRelayed()) {
            return;
        }

        $url = rtrim((string) config('terminal.relay.internal_url', 'http://relay:8080'), '/')."/internal/v1/sessions/{$session->id}/kick";

        try {
            $response = Http::withToken($this->tokens->controlToken($session, $keep))
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(2)
                ->post($url);

            if ($response->failed()) {
                Log::warning('Terminal relay refused to drop a session\'s clients.', ['session_id' => $session->id, 'status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('Terminal relay unreachable while dropping a session\'s clients.', ['session_id' => $session->id, 'error' => $e->getMessage()]);
        }
    }
}
