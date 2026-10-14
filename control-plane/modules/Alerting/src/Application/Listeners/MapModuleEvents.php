<?php

namespace Falak\Alerting\Application\Listeners;

use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentWentOffline;
use Falak\Insights\Events\HeartbeatMissed;
use Falak\Insights\Events\IssueOpened;
use Falak\Insights\Events\IssueRegressed;
use Falak\Insights\Events\IssueResolved;
use Falak\Insights\Events\ThresholdBreached;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Events\ServerAttentionCleared;
use Falak\Servers\Events\ServerNeedsAttention;
use Falak\Servers\Events\ServerProvisioned;

/**
 * Maps events of modules that predate the Alertable contract onto alerts.
 *
 * Every Insights event about an issue shares the dedup key "insights.issue:{id}" so one issue
 * produces one alert (IssueOpened + ThresholdBreached/HeartbeatMissed fire together); IssueResolved
 * clears the key so a regression alerts again.
 */
final class MapModuleEvents
{
    /** @var array<string, array{0: string, 1: string, 2: Severity}> type => [label, group, default severity] */
    public const TYPES = [
        'insights.issue_opened' => ['New issue', 'Insights', Severity::Warning],
        'insights.issue_regressed' => ['Issue regressed', 'Insights', Severity::Warning],
        'insights.issue_resolved' => ['Issue resolved', 'Insights', Severity::Info],
        'insights.threshold_breached' => ['Performance threshold breached', 'Insights', Severity::Warning],
        'insights.heartbeat_missed' => ['Scheduled task missed', 'Insights', Severity::Critical],
        'fleet.agent_offline' => ['Server agent offline', 'Fleet', Severity::Critical],
        'fleet.agent_online' => ['Server agent back online', 'Fleet', Severity::Info],
        'servers.provisioned' => ['Server provisioned', 'Servers', Severity::Info],
        'servers.needs_attention' => ['Server needs attention', 'Servers', Severity::Warning],
        'servers.attention_cleared' => ['Server no longer needs attention', 'Servers', Severity::Info],
    ];

    /** Issue kinds whose module announces them with its own alerts (uptime.monitor_down, …): no insights.issue_* alert. */
    public const SILENT_KINDS = ['uptime'];

    public function __construct(
        private readonly Alerts $alerts,
        private readonly ServerDirectory $servers,
    ) {}

    public static function registerTypes(AlertTypes $types): void
    {
        foreach (self::TYPES as $type => [$label, $group, $severity]) {
            $types->register($type, $label, $group, $severity);
        }
    }

    public function issueOpened(IssueOpened $event): void
    {
        if (in_array($event->kind, self::SILENT_KINDS, true)) {
            return;
        }

        $severity = in_array($event->priority, ['urgent', 'high'], true) ? Severity::Critical : Severity::Warning;

        $this->raise(new AlertData($event->organizationId, 'insights.issue_opened', $severity, "New issue: {$event->title}", (string) $event->culprit, $event->url,
            "insights.issue:{$event->issueId}", context: $this->issueContext($event)));
    }

    public function issueRegressed(IssueRegressed $event): void
    {
        if (in_array($event->kind, self::SILENT_KINDS, true)) {
            return;
        }

        $severity = in_array($event->priority, ['urgent', 'high'], true) ? Severity::Critical : Severity::Warning;

        $this->raise(new AlertData($event->organizationId, 'insights.issue_regressed', $severity, "Regression: {$event->title}", (string) $event->culprit, $event->url,
            "insights.issue:{$event->issueId}", context: $this->issueContext($event)));
    }

    public function issueResolved(IssueResolved $event): void
    {
        if (in_array($event->kind, self::SILENT_KINDS, true)) {
            return;
        }

        $this->raise(new AlertData($event->organizationId, 'insights.issue_resolved', Severity::Info, $event->title, (string) $event->culprit, $event->url,
            "insights.issue:{$event->issueId}", resolves: true, context: $this->issueContext($event)));
    }

    public function thresholdBreached(ThresholdBreached $event): void
    {
        $body = sprintf('%s of %s "%s" was %s ms over the last %d min (threshold %s ms).',
            $event->metric, str_replace('_', ' ', $event->eventType), $event->name, round($event->valueMs, 1), $event->windowMinutes, round($event->thresholdMs, 1));

        $this->raise(new AlertData($event->organizationId, 'insights.threshold_breached', Severity::Warning, "Slow {$event->eventType}: {$event->name}", $body, $event->url,
            "insights.issue:{$event->issueId}", context: [
                'site_id' => $event->siteId,
                'metric' => $event->metric,
                'value_ms' => round($event->valueMs, 1),
                'threshold_ms' => round($event->thresholdMs, 1),
                'window_minutes' => $event->windowMinutes,
            ]));
    }

    public function heartbeatMissed(HeartbeatMissed $event): void
    {
        $body = "Expected a run by {$event->expectedAt->format('Y-m-d H:i T')}"
            .($event->lastRunAt ? "; last run {$event->lastRunAt->format('Y-m-d H:i T')}." : '; it never reported a run.');

        $this->raise(new AlertData($event->organizationId, 'insights.heartbeat_missed', Severity::Critical, "Scheduled task missed: {$event->job}", $body, $event->url,
            "insights.issue:{$event->issueId}", context: array_filter([
                'site_id' => $event->siteId,
                'server_id' => $event->serverId,
                'schedule' => $event->schedule,
            ], fn ($value) => $value !== null)));
    }

    public function agentWentOffline(AgentWentOffline $event): void
    {
        $name = $this->serverName($event->serverId) ?? "agent {$event->agentId}";
        $last = $event->lastHeartbeatAt?->format('Y-m-d H:i:s T');

        $this->raise(new AlertData($event->organizationId, 'fleet.agent_offline', Severity::Critical, "Server {$name} is offline",
            $last ? "No heartbeat since {$last}." : 'The agent stopped sending heartbeats.',
            $event->serverId ? "/servers/{$event->serverId}" : null,
            'fleet.agent:'.($event->serverId ?? $event->agentId),
            context: array_filter(['server_id' => $event->serverId, 'agent_id' => $event->agentId])));
    }

    public function agentCameOnline(AgentCameOnline $event): void
    {
        $name = $this->serverName($event->serverId) ?? "agent {$event->agentId}";

        $this->raise(new AlertData($event->organizationId, 'fleet.agent_online', Severity::Info, "Server {$name} is back online", 'Heartbeats resumed.',
            $event->serverId ? "/servers/{$event->serverId}" : null,
            'fleet.agent:'.($event->serverId ?? $event->agentId), resolves: true,
            context: array_filter(['server_id' => $event->serverId, 'agent_id' => $event->agentId])));
    }

    public function serverProvisioned(ServerProvisioned $event): void
    {
        $this->raise(new AlertData($event->organizationId, 'servers.provisioned', Severity::Info, "Server {$event->name} is ready",
            "The {$event->type} server finished provisioning.", "/servers/{$event->serverId}", context: ['server_id' => $event->serverId, 'type' => $event->type]));
    }

    public function serverNeedsAttention(ServerNeedsAttention $event): void
    {
        $this->raise(new AlertData($event->organizationId, 'servers.needs_attention', Severity::Warning, "Server {$event->name} needs attention",
            'The machine check found '.(count($event->blocks) === 1 ? 'a conflict' : count($event->blocks).' conflicts').' to fix before provisioning: '.implode(' ', $event->blocks),
            "/servers/{$event->serverId}", 'servers.attention:'.$event->serverId, context: ['server_id' => $event->serverId]));
    }

    public function serverAttentionCleared(ServerAttentionCleared $event): void
    {
        $this->raise(new AlertData($event->organizationId, 'servers.attention_cleared', Severity::Info, "Server {$event->name} no longer needs attention",
            'Nothing on the machine blocks provisioning any more.', "/servers/{$event->serverId}", 'servers.attention:'.$event->serverId, resolves: true,
            context: ['server_id' => $event->serverId]));
    }

    private function raise(AlertData $alert): void
    {
        $this->alerts->raise($alert);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function issueContext(IssueOpened|IssueRegressed|IssueResolved $event): array
    {
        return array_filter(['kind' => $event->kind, 'priority' => $event->priority, 'site_id' => $event->siteId, 'server_id' => $event->serverId], fn ($value) => $value !== null);
    }

    private function serverName(?string $serverId): ?string
    {
        return $serverId ? $this->servers->find($serverId)?->name : null;
    }
}
