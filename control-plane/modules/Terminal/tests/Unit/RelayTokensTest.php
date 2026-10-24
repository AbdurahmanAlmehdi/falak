<?php

use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Infrastructure\RelayTokens;

// docs/plans/CONSOLE.md "Tokens": the relay (Go) has the same vector.
const RELAY_VECTOR_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const RELAY_VECTOR_PAYLOAD = '{"v":1,"sid":"01JTESTSESSION0000000000000","aud":"client","sub":"01JTESTUSER00000000000000000","w":true,"exp":1900000000,"n":"abc123"}';
const RELAY_VECTOR_TOKEN = 'eyJ2IjoxLCJzaWQiOiIwMUpURVNUU0VTU0lPTjAwMDAwMDAwMDAwMDAiLCJhdWQiOiJjbGllbnQiLCJzdWIiOiIwMUpURVNUVVNFUjAwMDAwMDAwMDAwMDAwMDAwIiwidyI6dHJ1ZSwiZXhwIjoxOTAwMDAwMDAwLCJuIjoiYWJjMTIzIn0.PtCiVt0KkqNOxHVpYCgRJumDkqjyxeKI2TIuZEm8x7E';

it('derives the key and signs the test vector', function () {
    $tokens = new RelayTokens(RELAY_VECTOR_KEY);

    expect(bin2hex($tokens->key()))->toBe('0f39093fd3813fb62ac265d353c61a32221cded696810b5a38c87671d252bc9e')
        ->and($tokens->signJson(RELAY_VECTOR_PAYLOAD))->toBe(RELAY_VECTOR_TOKEN)
        ->and($tokens->sign(json_decode(RELAY_VECTOR_PAYLOAD, true)))->toBe(RELAY_VECTOR_TOKEN);
});

it('uses a raw APP_KEY without the base64: prefix as bytes', function () {
    expect((new RelayTokens('raw-secret'))->key())->toBe(hash_hmac('sha256', RelayTokens::KEY_CONTEXT, 'raw-secret', true))
        ->and((new RelayTokens('raw-secret'))->key())->not->toBe((new RelayTokens(RELAY_VECTOR_KEY))->key());
});

it('issues agent tokens and client tickets with the documented claims', function () {
    config(['app.key' => RELAY_VECTOR_KEY, 'terminal.max_duration' => 3600]);
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $session = (new TerminalSession)->forceFill(['id' => '01jtestsession0000000000000', 'server_id' => '01jserver00000000000000000']);
    $tokens = new RelayTokens;
    $claims = function (string $token) use ($tokens) {
        [$body, $signature] = explode('.', $token);
        expect(rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $tokens->key(), true)), '+/', '-_'), '='))->toBe($signature);

        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    };

    $fp = str_repeat('a1', 32); // 64-char hex fingerprint
    $agent = $claims($tokens->agentToken($session, $fp));
    $client = $claims($tokens->clientTicket($session, '01juser', false));

    expect($agent)->toMatchArray(['v' => 1, 'sid' => $session->id, 'aud' => 'agent', 'sub' => $session->server_id, 'w' => true, 'exp' => 1_800_000_000 + 3900, 'fp' => $fp])
        ->and($agent['n'])->toBeString()->not->toBeEmpty()
        ->and($client)->toMatchArray(['v' => 1, 'sid' => $session->id, 'aud' => 'client', 'sub' => '01juser', 'w' => false, 'exp' => 1_800_000_060])
        ->and($client['n'])->not->toBe($claims($tokens->clientTicket($session, '01juser', false))['n']);
});

it('rejects agentToken when the fingerprint is absent (fail closed)', function () {
    config(['app.key' => RELAY_VECTOR_KEY]);
    $session = (new TerminalSession)->forceFill(['id' => '01jtestsession0000000000000', 'server_id' => '01jserver00000000000000000']);
    $tokens = new RelayTokens;

    expect(fn () => $tokens->agentToken($session, null))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $tokens->agentToken($session, ''))->toThrow(\InvalidArgumentException::class);
});

it('derives the client URL from APP_URL unless one is configured', function () {
    config(['app.url' => 'https://panel.example.com/', 'terminal.relay.url' => null]);
    expect(RelayTokens::baseUrl())->toBe('wss://panel.example.com/terminal/v1');

    config(['app.url' => 'http://localhost:8000']);
    expect(RelayTokens::baseUrl())->toBe('ws://localhost:8000/terminal/v1');

    config(['terminal.relay.url' => 'wss://relay.example.com/terminal/v1/']);
    expect(RelayTokens::baseUrl())->toBe('wss://relay.example.com/terminal/v1');
});
