<?php

use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Insights\Events\IssueOpened;
use Falak\Kernel\Network\EndpointGuard;

const ALERTING_SLACK_URL = 'https://hooks.slack.com/services/T000/B000/SECRETSECRETSECRET';

/**
 * Webhook hosts resolve to a public address in tests (no DNS), or to the addresses given per host.
 *
 * @param  array<string, list<string>>  $hosts
 */
function alerting_guard(array $hosts = []): void
{
    app()->instance(EndpointGuard::class, new EndpointGuard(fn (string $host) => $hosts[$host] ?? ['93.184.216.34']));
}

function alerting_channel(string $organizationId, ChannelType $type = ChannelType::Slack, array $config = [], array $attributes = []): Channel
{
    $defaults = match ($type) {
        ChannelType::Email => ['recipients' => ['ops@example.com']],
        ChannelType::Slack => ['webhook_url' => ALERTING_SLACK_URL],
        ChannelType::Discord => ['webhook_url' => 'https://discord.com/api/webhooks/123/SECRETTOKEN'],
        ChannelType::Telegram => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'chat_id' => '-100123'],
        ChannelType::Webhook => ['url' => 'https://hooks.example.com/falak', 'secret' => 'a-very-long-signing-secret'],
    };

    return Channel::query()->create([
        'organization_id' => $organizationId,
        'type' => $type,
        'name' => $type->value.'-'.str()->random(6),
        'config' => array_replace($defaults, $config),
        'enabled' => true,
        ...$attributes,
    ]);
}

/**
 * @param  list<string>  $types
 * @param  list<Channel>  $channels
 */
function alerting_rule(string $organizationId, array $types = ['*'], array $channels = [], array $attributes = []): Rule
{
    $rule = Rule::query()->create([
        'organization_id' => $organizationId,
        'name' => 'Rule '.str()->random(6),
        'event_types' => $types,
        'min_severity' => 'info',
        'enabled' => true,
        ...$attributes,
    ]);
    $rule->channels()->sync(array_map(fn (Channel $channel) => $channel->id, $channels));

    return $rule;
}

/** Deletes the organization's default rule pack (tests of routing through explicit rules only). */
function alerting_without_default_pack(string $organizationId): void
{
    Rule::query()->where('organization_id', $organizationId)->whereNotNull('pack_key')->delete();
}

function alerting_issue_opened(string $organizationId, string $issueId = '01JISSUE000000000000000001', string $priority = 'none'): IssueOpened
{
    return new IssueOpened($issueId, $organizationId, '01JSITE0000000000000000001', null, 'exception', 'RuntimeException: boom', 'App\\Http\\Controllers\\Foo@bar', $priority, "/insights/issues/{$issueId}");
}
