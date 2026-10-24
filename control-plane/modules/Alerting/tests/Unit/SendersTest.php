<?php

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Application\Mail\AlertMail;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Infrastructure\Senders\DeliveryFailed;
use Falak\Alerting\Infrastructure\Senders\DiscordSender;
use Falak\Alerting\Infrastructure\Senders\EmailSender;
use Falak\Alerting\Infrastructure\Senders\HttpSender;
use Falak\Alerting\Infrastructure\Senders\SlackSender;
use Falak\Alerting\Infrastructure\Senders\TelegramSender;
use Falak\Alerting\Infrastructure\Senders\WebhookSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    alerting_guard(['127.0.0.1.nip.io' => ['127.0.0.1'], 'localhost' => ['127.0.0.1'], 'metadata.internal' => ['169.254.169.254'], 'rebind.example.com' => ['93.184.216.34', '169.254.169.254'], 'lan.example.com' => ['10.0.0.5']]);
    $this->message = new AlertMessage(
        '01JALERT000000000000000001', '01JORG00000000000000000001', 'fleet.agent_offline', Severity::Critical,
        'Server web-1 is offline', 'No heartbeat since 12:00 <UTC>.', 'https://panel.test/servers/1', ['server_id' => 'srv', 'flag' => true], false,
        new DateTimeImmutable('2026-09-27T12:00:00Z'),
    );
});

it('posts Slack blocks with escaped text', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

    (new SlackSender)->send(['webhook_url' => 'https://hooks.slack.com/services/T/B/X'], $this->message);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://hooks.slack.com/services/T/B/X'
            && $request['text'] === '[CRITICAL] Server web-1 is offline'
            && str_contains($request['blocks'][0]['text']['text'], '*<https://panel.test/servers/1|[CRITICAL] Server web-1 is offline>*')
            && str_contains($request['blocks'][0]['text']['text'], '12:00 &lt;UTC&gt;.')
            && $request['blocks'][1]['type'] === 'context'
            && $request['blocks'][1]['elements'][0]['text'] === 'Critical · fleet.agent_offline';
    });
});

it('raises a secret-free error when Slack rejects the message', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('invalid_token', 403)]);

    expect(fn () => (new SlackSender)->send(['webhook_url' => 'https://hooks.slack.com/services/T/B/SECRET'], $this->message))
        ->toThrow(DeliveryFailed::class, 'HTTP 403: invalid_token');
});

it('posts a Discord embed and accepts 204', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    (new DiscordSender)->send(['webhook_url' => 'https://discord.com/api/webhooks/1/abc'], $this->message);

    Http::assertSent(function (Request $request) {
        $embed = $request['embeds'][0];

        return $embed['title'] === '[CRITICAL] Server web-1 is offline'
            && $embed['color'] === 0xDC2626
            && $embed['url'] === 'https://panel.test/servers/1'
            && $embed['timestamp'] === '2026-09-27T12:00:00+00:00'
            && $embed['fields'][0] === ['name' => 'Severity', 'value' => 'Critical', 'inline' => true]
            && collect($embed['fields'])->contains(fn ($f) => $f['name'] === 'flag' && $f['value'] === 'yes')
            && $request['allowed_mentions'] === ['parse' => []];
    });
});

it('sends Telegram HTML messages', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new TelegramSender)->send(['bot_token' => '123:TOKEN', 'chat_id' => '-100'], $this->message);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot123:TOKEN/sendMessage'
        && $request['chat_id'] === '-100'
        && $request['parse_mode'] === 'HTML'
        && $request['disable_web_page_preview'] === true
        && str_starts_with($request['text'], '<b>[CRITICAL] Server web-1 is offline</b>')
        && str_contains($request['text'], '12:00 &lt;UTC&gt;.')
        && str_contains($request['text'], '<a href="https://panel.test/servers/1">Open in Falak</a>'));
});

it('treats Telegram ok=false as a failure and redacts the bot token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found for 123:TOKEN'])]);

    try {
        (new TelegramSender)->send(['bot_token' => '123:TOKEN', 'chat_id' => '-100'], $this->message);
        $this->fail('Expected DeliveryFailed');
    } catch (DeliveryFailed $e) {
        expect($e->getMessage())->toBe('Telegram error: Bad Request: chat not found for [redacted]');
    }
});

it('signs generic webhooks with HMAC-SHA256 over timestamp and body', function () {
    Http::fake(['hooks.example.com/*' => Http::response(['received' => true])]);
    $this->travelTo(new DateTimeImmutable('2026-09-27T12:00:05Z'));

    (new WebhookSender)->send(['url' => 'https://hooks.example.com/falak', 'secret' => 'shhh-secret-value'], $this->message);

    Http::assertSent(function (Request $request) {
        $timestamp = $request->header('X-Falak-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shhh-secret-value');
        $payload = json_decode($request->body(), true);

        return $timestamp === (string) strtotime('2026-09-27T12:00:05Z')
            && hash_equals($expected, $request->header('X-Falak-Signature')[0])
            && $request->header('X-Falak-Event')[0] === 'fleet.agent_offline'
            && $request->header('Content-Type')[0] === 'application/json'
            && $payload['type'] === 'fleet.agent_offline'
            && $payload['severity'] === 'critical'
            && $payload['resolved'] === false
            && $payload['context'] === ['server_id' => 'srv', 'flag' => true]
            && $payload['organization_id'] === '01JORG00000000000000000001';
    });
});

it('refuses to deliver webhooks to private addresses, by IP or by name', function (string $url) {
    Http::fake();

    expect(fn () => (new WebhookSender)->send(['url' => $url, 'secret' => 'x'], $this->message))->toThrow(DeliveryFailed::class);
    Http::assertNothingSent();
})->with(['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'http://192.168.1.1/', 'http://[::1]/', 'http://169.254.169.254/latest', 'http://localhost:8080/', 'http://metadata.internal/', 'http://2130706433/', 'http://127.0.0.1.nip.io/hook', 'https://rebind.example.com/hook', 'ftp://hooks.example.com/hook']);

it('follows no redirects from a webhook target', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    try {
        (new WebhookSender)->send(['url' => 'https://hooks.example.com/falak', 'secret' => 'x'], $this->message);
        $this->fail('Expected DeliveryFailed');
    } catch (DeliveryFailed $e) {
        expect($e->getMessage())->toBe('HTTP 302');
    }

    Http::assertSentCount(1);
});

it('reports webhook error answers by status only, Slack ones with their body', function () {
    Http::fake([
        'hooks.example.com/*' => Http::response('internal secrets of the target', 500),
        'hooks.slack.com/*' => Http::response('invalid_token', 403),
    ]);

    expect(fn () => (new WebhookSender)->send(['url' => 'https://hooks.example.com/falak', 'secret' => 'x'], $this->message))
        ->toThrow(DeliveryFailed::class, 'HTTP 500')
        ->and(fn () => (new SlackSender)->send(['webhook_url' => 'https://hooks.slack.com/services/T/B/X'], $this->message))
        ->toThrow(DeliveryFailed::class, 'HTTP 403: invalid_token');

    try {
        (new WebhookSender)->send(['url' => 'https://hooks.example.com/falak', 'secret' => 'x'], $this->message);
    } catch (DeliveryFailed $e) {
        expect($e->getMessage())->toBe('HTTP 500');
    }
});

it('allows private webhook targets only when configured, and never on a managed install', function () {
    Http::fake(['10.0.0.5/*' => Http::response('ok'), 'lan.example.com/*' => Http::response('ok')]);
    config(['alerting.allow_private_webhooks' => true]);

    (new WebhookSender)->send(['url' => 'http://lan.example.com/hook', 'secret' => 'x'], $this->message);
    Http::assertSentCount(1);

    config(['controlplane.managed' => true]);
    expect(fn () => (new WebhookSender)->send(['url' => 'http://lan.example.com/hook', 'secret' => 'x'], $this->message))->toThrow(DeliveryFailed::class, 'private');
    Http::assertSentCount(1);
});

it('reports connection failures without leaking the URL', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://hooks.example.com/secret-path'));

    try {
        (new WebhookSender)->send(['url' => 'https://hooks.example.com/secret-path', 'secret' => 'x'], $this->message);
        $this->fail('Expected DeliveryFailed');
    } catch (DeliveryFailed $e) {
        expect($e->getMessage())->toStartWith('Connection failed')->not->toContain('secret-path');
    }
});

it('sends alert emails to every recipient', function () {
    Mail::fake();

    (new EmailSender)->send(['recipients' => ['a@example.com', 'b@example.com']], $this->message);

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('a@example.com') && $mail->hasTo('b@example.com')
        && $mail->envelope()->subject === '[CRITICAL] Server web-1 is offline');
});

it('renders the alert email', function () {
    $html = (new AlertMail($this->message))->render();

    expect($html)->toContain('Server web-1 is offline')->toContain('https://panel.test/servers/1')->toContain('server_id');
});

it('masks secrets for the UI', function () {
    expect(HttpSender::maskValue('https://hooks.slack.com/services/T/B/SECRETabcd'))->toBe('https://hooks.slack.com/…/abcd')
        ->and((new TelegramSender)->mask(['bot_token' => '123456:ABCDEFGHIJKLMNOP', 'chat_id' => '-1'])['bot_token'])->toBe('••••MNOP')
        ->and((new WebhookSender)->mask(['url' => 'https://x.example.com/a/b', 'secret' => 'short'])['secret'])->toBe('••••');
});
