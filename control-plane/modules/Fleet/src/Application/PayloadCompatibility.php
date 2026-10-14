<?php

namespace Falak\Fleet\Application;

use Falak\Fleet\Events\AgentVersionChanged;

/**
 * Agents decode payloads strictly (unknown fields are rejected), so optional fields added to the protocol are
 * removed for agents whose facts do not list the feature that introduced them (`facts.features`). Modules
 * always build the full payload; an agent that is upgraded later gets the fields on the next dispatch
 * (listen to {@see AgentVersionChanged} to re-send state that is otherwise deduplicated).
 */
final class PayloadCompatibility
{
    /**
     * feature => [command type => list of field paths ("*" = every list item)].
     *
     * @var array<string, array<string, list<string>>>
     */
    public const FIELDS = [
        'edge.access_log' => [
            'edge.caddy.apply' => ['sites.*.access_log'],
        ],
        // Function paths on other sites; agents without it route nothing there (no error).
        'fn.v2' => [
            'edge.caddy.apply' => ['sites.*.mounts'],
        ],
        'telemetry.log_kind' => [
            'telemetry.configure' => ['log_sources.*.kind', 'log_sources.*.multiline'],
        ],
        // Workload metrics (docs/plans/OBSERVABILITY.md phase 1): a native site without a slice is measured by the
        // processes of its unix user. Older agents measure no workloads.
        'telemetry.workloads' => [
            'telemetry.configure' => ['sites.*.unix_user', 'sites.*.runtime'],
        ],
        // A shared user's processes attributed per site; older agents get unix_user for isolated sites only (UNLESS).
        'telemetry.workloads.shared' => [
            'telemetry.configure' => ['sites.*.isolated'],
        ],
        // Log sources v2 (docs/plans/OBSERVABILITY.md phase 2). Container sources are removed whole first (DROP).
        'logs.container_files' => [
            'telemetry.configure' => ['log_sources.*.container', 'log_sources.*.compose_service', 'log_sources.*.multiline_pattern', 'log_sources.*.origin'],
        ],
        // The pool's slow log; older agents run the pool without one.
        'runtime.fpm.slowlog' => [
            'runtime.fpm.pool' => ['slowlog_timeout'],
        ],
        // Split-out compose services joining their stack's network; older agents run them on their own network only.
        'docker.networks' => [
            'docker.run' => ['networks'],
            'deploy.container.swap' => ['networks'],
        ],
        // A compose project's missing network created with Compose's labels; older agents only wait for it.
        // A stack's bootstrap pass (only the services its split-out sites use). The control plane only plans one for
        // agents that have the feature: stripping the field would start the whole stack.
        'compose.up.services' => [
            'docker.compose.up' => ['services'],
        ],
        'docker.networks.create' => [
            'docker.run' => ['networks.*.compose'],
            'deploy.container.swap' => ['networks.*.compose'],
        ],
        // Machine-check decisions. Older agents never get provision.inspect, so their plans carry no decisions; the
        // field is stripped all the same (a plan built from a report an upgraded agent sent, then downgraded).
        'provision.v2' => [
            'provision.apply' => ['components'],
        ],
        // Database observability (docs/plans/OBSERVABILITY.md phase 3): query insights need a restart; older agents keep
        // the container as it is.
        'db.observability' => [
            'db.instance.create' => ['instance.query_insights'],
            // apply_query_insights: only EnableQueryInsights sends it, the one update that may restart for it.
            'db.instance.update' => ['instance.query_insights', 'apply_query_insights'],
        ],
        // X-Request-Id stamped by the edge (docs/plans/network/http.md); older agents render the config they always did.
        'edge.request_id' => [
            'edge.caddy.apply' => ['request_id'],
        ],
        // Network protection (docs/plans/network/protection.md): rule counters; older agents render the same rules
        // without them. net.protection.apply itself is only ever sent to agents with the feature.
        'net.protection' => [
            'net.firewall.apply' => ['counters'],
        ],
    ];

    /**
     * feature => [command type => [list field path => flag]]: agents without the feature do not get the list items whose
     * flag is true at all (applied first). For items whose meaning the flag changes: without it, a container log
     * source's path would be tailed on the host.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    public const DROP = [
        'logs.container_files' => [
            'telemetry.configure' => ['log_sources' => 'container'],
        ],
    ];

    /**
     * feature => [command type => [field path => flag]]: agents without the feature get the field only in the objects
     * where its sibling flag is true (applied before FIELDS, which may strip the flag itself).
     *
     * @var array<string, array<string, array<string, string>>>
     */
    public const UNLESS = [
        // Older agents measure a site with a unix_user by every process that user owns: a non-isolated site's user is
        // the one every such site (and the servers' admin logins) shares.
        'telemetry.workloads.shared' => [
            'telemetry.configure' => ['sites.*.unix_user' => 'isolated'],
        ],
    ];

    /**
     * feature => [command type => [top-level field => the value agents without the feature get]].
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public const VALUES = [];

    /**
     * @param  list<string>  $features  the agent's reported features
     */
    public static function adapt(string $type, object $document, array $features): object
    {
        // Values only an agent with the feature may get: older agents get the safe value instead (the field itself is
        // understood).
        foreach (self::VALUES as $feature => $commands) {
            if (in_array($feature, $features, true) || ! isset($commands[$type])) {
                continue;
            }

            foreach ($commands[$type] as $field => $value) {
                if (property_exists($document, $field)) {
                    $document->{$field} = $value;
                }
            }
        }

        foreach (self::DROP as $feature => $commands) {
            if (in_array($feature, $features, true) || ! isset($commands[$type])) {
                continue;
            }

            foreach ($commands[$type] as $path => $flag) {
                self::drop($document, explode('.', $path), $flag);
            }
        }

        foreach (self::UNLESS as $feature => $commands) {
            if (in_array($feature, $features, true) || ! isset($commands[$type])) {
                continue;
            }

            foreach ($commands[$type] as $path => $flag) {
                self::strip($document, explode('.', $path), $flag);
            }
        }

        foreach (self::FIELDS as $feature => $commands) {
            if (in_array($feature, $features, true) || ! isset($commands[$type])) {
                continue;
            }

            foreach ($commands[$type] as $path) {
                self::strip($document, explode('.', $path));
            }
        }

        return $document;
    }

    /**
     * Removes the items whose flag is true from the list at the object path (no "*" segments).
     *
     * @param  list<string>  $segments
     */
    private static function drop(object $node, array $segments, string $flag): void
    {
        $key = (string) array_shift($segments);

        if (! property_exists($node, $key)) {
            return;
        }

        if ($segments !== []) {
            if (is_object($node->{$key})) {
                self::drop($node->{$key}, $segments, $flag);
            }

            return;
        }

        if (is_array($node->{$key})) {
            $node->{$key} = array_values(array_filter($node->{$key}, fn (mixed $item) => ! is_object($item) || ($item->{$flag} ?? false) !== true));
        }
    }

    /**
     * @param  list<string>  $segments
     * @param  ?string  $unless  a sibling flag that keeps the field where it is true
     */
    private static function strip(mixed $node, array $segments, ?string $unless = null): void
    {
        $key = array_shift($segments);

        if ($key === '*') {
            foreach (is_array($node) ? $node : [] as $item) {
                self::strip($item, $segments, $unless);
            }

            return;
        }

        if (! is_object($node) || ! property_exists($node, (string) $key)) {
            return;
        }

        if ($segments === []) {
            if ($unless === null || ($node->{$unless} ?? false) !== true) {
                unset($node->{$key});
            }

            return;
        }

        self::strip($node->{$key}, $segments, $unless);
    }
}
