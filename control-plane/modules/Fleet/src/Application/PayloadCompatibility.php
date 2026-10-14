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
     * @param  list<string>  $segments
     */
    private static function strip(mixed $node, array $segments): void
    {
        $key = array_shift($segments);

        if ($key === '*') {
            foreach (is_array($node) ? $node : [] as $item) {
                self::strip($item, $segments);
            }

            return;
        }

        if (! is_object($node) || ! property_exists($node, (string) $key)) {
            return;
        }

        if ($segments === []) {
            unset($node->{$key});

            return;
        }

        self::strip($node->{$key}, $segments);
    }
}
