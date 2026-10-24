<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Closure;
use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Kernel\Network\EndpointGuard;

/**
 * Generic JSON webhook signed with HMAC-SHA256. Config: {url, secret}.
 *
 * Receivers verify: `X-Falak-Signature == "sha256=" . hmac_sha256(secret, X-Falak-Timestamp . "." . raw_body)`
 * and reject stale timestamps.
 */
final class WebhookSender extends HttpSender
{
    public function type(): ChannelType
    {
        return ChannelType::Webhook;
    }

    public function rules(): array
    {
        return [
            'config.url' => ['required', 'string', 'max:2000', 'url:http,https', $this->publicHost()],
            'config.secret' => ['required', 'string', 'min:16', 'max:255'],
        ];
    }

    public function secretKeys(): array
    {
        return ['url', 'secret'];
    }

    public function send(array $config, AlertMessage $message): void
    {
        $url = (string) $config['url'];
        $secret = (string) $config['secret'];

        $body = (string) json_encode($message->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        $this->post($url, $body, [
            'X-Falak-Event' => $message->type,
            'X-Falak-Delivery' => $message->id,
            'X-Falak-Timestamp' => $timestamp,
            'X-Falak-Signature' => self::signature($secret, $timestamp, $body),
        ], [$secret], guarded: true);
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function publicHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // Resolved here too (delivery checks again, and pins the address): a name for a private address is refused.
            if (is_string($value) && app(EndpointGuard::class)->refusal($value, self::allowPrivate(), ['http', 'https']) !== null) {
                $fail('The webhook URL must point to a public host.');
            }
        };
    }
}
