<?php

namespace Falak\Sites\Infrastructure\Compose;

use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\Data\ComposeServiceSummary;
use Falak\Sites\Contracts\Data\ComposeSummary;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Compose file parser + policy (docs/COMPOSE_TEMPLATES.md §1.3). Policy without "Allow privileged compose":
 * no privileged containers, host network / pid / ipc / userns namespaces, capabilities beyond Docker's
 * default set, devices, unconfined security profiles, host bind mounts outside the release directory or
 * the Docker socket. Named volumes are always allowed.
 */
final class YamlComposeInspector implements ComposeInspector
{
    public const LEADER_COMMAND_LABEL = 'falak.deploy.leader_command';

    /** "none" keeps a service off the environment network, where the environment's databases answer (agent). */
    public const ENVIRONMENT_NETWORK_LABEL = 'falak.environment_network';

    public function parse(string $yaml): ComposeSummary
    {
        $max = (int) config('sites.compose.max_bytes', 262144);

        if (strlen($yaml) > $max) {
            return new ComposeSummary([], [], [], ['The compose file is larger than '.intdiv($max, 1024).' KB.']);
        }

        try {
            $doc = self::load($yaml);
        } catch (ParseException $e) {
            return new ComposeSummary([], [], [], ['YAML: '.$e->getMessage()]);
        }

        if (! is_array($doc)) {
            return new ComposeSummary([], [], [], ['The compose file must be a mapping with a `services` section.']);
        }

        $errors = [];
        $violations = [];
        $warnings = [];
        $services = [];
        $definitions = $doc['services'] ?? null;

        if (! is_array($definitions) || $definitions === [] || array_is_list($definitions)) {
            $errors[] = 'The compose file has no services.';
            $definitions = [];
        }

        foreach (['include', 'extends'] as $unsupported) {
            if (array_key_exists($unsupported, $doc)) {
                $errors[] = "Top-level `{$unsupported}` is not supported.";
            }
        }

        $volumes = [];
        $volumeDefinitions = [];

        foreach ((array) ($doc['volumes'] ?? []) as $name => $volume) {
            $volumes[] = (string) $name;
            // The Docker volume behind a key: `name:` when set (external volumes too, legacy `external: {name: x}`
            // included), else Compose's <project>_<key>.
            $legacy = is_array($volume) && is_array($volume['external'] ?? null) && is_string($volume['external']['name'] ?? null) && $volume['external']['name'] !== ''
                ? $volume['external']['name'] : null;
            $volumeDefinitions[(string) $name] = [
                'name' => is_array($volume) && is_string($volume['name'] ?? null) && $volume['name'] !== '' ? $volume['name'] : $legacy,
                'external' => is_array($volume) && (($volume['external'] ?? false) === true || is_array($volume['external'] ?? null)),
            ];
            $device = is_array($volume) ? ($volume['driver_opts']['device'] ?? null) : null;

            if (is_string($device) && str_starts_with($device, '/')) {
                $violations[] = "Volume {$name} binds the host path {$device} (driver_opts.device).";
            }
        }

        // Falak's own networks (environment networks with database containers, falak-*) and labels (falak.*: managed
        // containers, volumes and networks, their secrets) are never claimed by a compose file: Falak joins the stack to
        // its environment's network itself. Hard errors, whatever the privileged-compose setting.
        foreach ((array) ($doc['networks'] ?? []) as $key => $network) {
            $network = is_array($network) ? $network : [];
            $external = $network['external'] ?? false;
            $real = is_string($network['name'] ?? null) ? $network['name'] : (is_array($external) && is_string($external['name'] ?? null) ? $external['name'] : ((($external === true) ? (string) $key : null)));

            if ($real !== null && str_starts_with(strtolower($real), 'falak')) {
                $errors[] = "Network {$key} is {$real}: Falak's networks are not available to compose files (Falak connects the stack to its environment's network itself).";
            }

            if (self::falakLabels($network['labels'] ?? []) !== []) {
                $errors[] = "Network {$key} sets Falak's labels (falak.*).";
            }
        }

        foreach ((array) ($doc['volumes'] ?? []) as $key => $volume) {
            if (is_array($volume) && self::falakLabels($volume['labels'] ?? []) !== []) {
                $errors[] = "Volume {$key} sets Falak's labels (falak.*).";
            }

            // The control plane's own volumes (compose project falak: falak_pg-data …) and Falak's falak-* volumes.
            $real = $volumeDefinitions[(string) $key]['name'] ?? (($volumeDefinitions[(string) $key]['external'] ?? false) ? (string) $key : null);

            if ($real !== null && preg_match('/^falak[_-]/i', $real) === 1) {
                $errors[] = "Volume {$key} is {$real}: Falak's own volumes are not available to compose files.";
            }
        }

        $safe = array_map('strtoupper', (array) config('sites.compose.safe_capabilities', []));

        foreach ($definitions as $name => $service) {
            $name = (string) $name;

            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
                $errors[] = "Service name “{$name}” is invalid.";

                continue;
            }

            if (! is_array($service)) {
                $service = [];
            }

            if (isset($service['extends'])) {
                $errors[] = "Service {$name}: `extends` is not supported.";
            }

            $image = isset($service['image']) && is_scalar($service['image']) ? (string) $service['image'] : null;
            $build = array_key_exists('build', $service);

            if ($image === null && ! $build) {
                $errors[] = "Service {$name} has neither `image` nor `build`.";
            }

            // Another container's namespaces (a database container's, any container outside the stack) are never
            // shared; `service:` only names a service of this file.
            foreach (['network_mode', 'pid', 'ipc'] as $key) {
                $mode = is_string($service[$key] ?? null) ? $service[$key] : '';

                if (str_starts_with($mode, 'container:')) {
                    $errors[] = "Service {$name}: {$key}: {$mode} shares another container's namespace; only services of this file can (service:<name>).";
                } elseif (str_starts_with($mode, 'service:') && ! array_key_exists(substr($mode, 8), (array) $definitions)) {
                    $errors[] = "Service {$name}: {$key}: {$mode} names no service of this file.";
                }
            }

            foreach ((array) ($service['volumes_from'] ?? []) as $from) {
                if (is_string($from) && preg_match('/^container:falak/i', $from) === 1) {
                    $errors[] = "Service {$name}: volumes_from {$from} mounts a Falak container's volumes.";
                }
            }

            if (($forbidden = self::falakLabels($service['labels'] ?? [])) !== []) {
                $errors[] = "Service {$name} sets Falak's labels (".implode(', ', $forbidden).').';
            }

            // ---- policy
            if (($service['privileged'] ?? false) === true) {
                $violations[] = "Service {$name} runs privileged.";
            }

            foreach (['network_mode' => 'network', 'pid' => 'PID', 'ipc' => 'IPC', 'userns_mode' => 'user'] as $key => $label) {
                if (($service[$key] ?? null) === 'host') {
                    $violations[] = "Service {$name} uses the host {$label} namespace ({$key}: host).";
                }
            }

            foreach ((array) ($service['cap_add'] ?? []) as $capability) {
                $capability = strtoupper(preg_replace('/^CAP_/i', '', (string) $capability) ?? '');

                if ($capability === 'ALL' || ! in_array($capability, $safe, true)) {
                    $violations[] = "Service {$name} adds the capability {$capability}.";
                }
            }

            if (! empty($service['devices'])) {
                $violations[] = "Service {$name} maps host devices.";
            }

            foreach ((array) ($service['security_opt'] ?? []) as $option) {
                if (preg_match('/unconfined/i', (string) $option) === 1) {
                    $violations[] = "Service {$name} disables a security profile ({$option}).";
                }
            }

            [$named, $binds, $mounts] = $this->volumes($service['volumes'] ?? [], $volumes);

            foreach ($binds as $source) {
                if (str_contains($source, 'docker.sock')) {
                    $violations[] = "Service {$name} mounts the Docker socket.";
                } elseif (! self::insideRelease($source)) {
                    $violations[] = "Service {$name} bind-mounts the host path {$source} (only paths inside the release directory are allowed).";
                }
            }

            [$ports, $published] = $this->ports($service['ports'] ?? [], $service['expose'] ?? []);

            if ($published !== []) {
                $warnings[] = "Host ports of {$name} (".implode(', ', $published).') are not published; Falak publishes public services itself.';
            }

            $labels = self::labels($service['labels'] ?? []);
            $leader = isset($labels[self::LEADER_COMMAND_LABEL]) && trim($labels[self::LEADER_COMMAND_LABEL]) !== '' ? trim($labels[self::LEADER_COMMAND_LABEL]) : null;

            $healthcheck = is_array($service['healthcheck'] ?? null) && ($service['healthcheck']['disable'] ?? false) !== true;

            $services[] = new ComposeServiceSummary(
                name: $name,
                image: $image,
                build: $build,
                ports: $ports,
                publishedPorts: $published,
                volumes: $named,
                bindMounts: $binds,
                healthcheck: $healthcheck,
                dependsOn: array_values(array_map('strval', is_array($service['depends_on'] ?? null) ? (array_is_list($service['depends_on']) ? $service['depends_on'] : array_keys($service['depends_on'])) : [])),
                leaderCommand: $leader,
                namedMounts: $mounts,
            );
        }

        return new ComposeSummary($services, $volumes, array_values(array_unique($violations)), $errors, $warnings, $volumeDefinitions);
    }

    /**
     * YAML → array. Compose files are YAML 1.2-ish; Symfony handles anchors and merge keys.
     *
     * @throws ParseException
     */
    public static function load(string $yaml): mixed
    {
        return Yaml::parse($yaml);
    }

    /**
     * Labels in Falak's namespace (falak.*) a compose file may not set. falak.deploy.leader_command and
     * falak.environment_network are the stack's own; falak.site, falak.release and falak.service are the ones Falak's rendering sets (and overwrites).
     *
     * @return list<string>
     */
    public static function falakLabels(mixed $labels): array
    {
        return array_values(array_filter(array_keys(self::labels($labels)), fn (string $key) => str_starts_with(strtolower($key), 'falak.') && ! in_array($key, [self::LEADER_COMMAND_LABEL, self::ENVIRONMENT_NETWORK_LABEL, 'falak.site', 'falak.release', 'falak.service'], true)));
    }

    /**
     * Normalise `labels` (map or list of "k=v") into a map.
     *
     * @return array<string, string>
     */
    public static function labels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        if (! array_is_list($labels)) {
            return array_map(fn ($value) => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : '', array_combine(array_map('strval', array_keys($labels)), $labels));
        }

        $map = [];

        foreach ($labels as $label) {
            [$key, $value] = array_pad(explode('=', (string) $label, 2), 2, '');
            $map[$key] = $value;
        }

        return $map;
    }

    /** Relative paths that stay inside the release directory (./data, data/config). */
    public static function insideRelease(string $source): bool
    {
        if ($source === '' || str_starts_with($source, '/') || str_starts_with($source, '~') || str_contains($source, '$')) {
            return false;
        }

        $depth = 0;

        foreach (explode('/', $source) as $segment) {
            if ($segment === '..') {
                $depth--;
            } elseif ($segment !== '' && $segment !== '.') {
                $depth++;
            }

            if ($depth < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $declared  top-level named volumes
     * @return array{0: list<string>, 1: list<string>, 2: list<array{volume: string, target: string, read_only: bool}>} named volumes, bind sources, named volume mounts
     */
    private function volumes(mixed $volumes, array $declared): array
    {
        $named = [];
        $binds = [];
        $mounts = [];

        foreach (is_array($volumes) ? $volumes : [] as $volume) {
            if (is_array($volume)) {
                $type = (string) ($volume['type'] ?? 'volume');
                $source = (string) ($volume['source'] ?? '');

                if ($type === 'bind') {
                    $binds[] = $source;
                } elseif ($type === 'volume' && $source !== '') {
                    $named[] = $source;

                    if (is_string($volume['target'] ?? null) && str_starts_with($volume['target'], '/')) {
                        $mounts[] = ['volume' => $source, 'target' => $volume['target'], 'read_only' => ($volume['read_only'] ?? false) === true];
                    }
                }

                continue;
            }

            $parts = explode(':', (string) $volume);

            if (count($parts) < 2) {
                continue; // anonymous volume
            }

            $source = $parts[0];

            if (str_starts_with($source, '.') || str_starts_with($source, '/') || str_starts_with($source, '~') || str_contains($source, '/')) {
                $binds[] = $source;
            } else {
                $named[] = $source;

                if (str_starts_with($parts[1], '/')) {
                    $mounts[] = ['volume' => $source, 'target' => $parts[1], 'read_only' => in_array('ro', explode(',', $parts[2] ?? ''), true)];
                }
            }
        }

        return [array_values(array_unique($named)), array_values(array_unique($binds)), $mounts];
    }

    /**
     * @return array{0: list<int>, 1: list<string>} container ports, host mappings
     */
    private function ports(mixed $ports, mixed $expose): array
    {
        $container = [];
        $published = [];

        foreach (is_array($ports) ? $ports : [] as $port) {
            if (is_array($port)) {
                if (is_numeric($port['target'] ?? null)) {
                    $container[] = (int) $port['target'];
                }

                if (isset($port['published']) && $port['published'] !== '') {
                    $published[] = ($port['published']).':'.($port['target'] ?? '?');
                }

                continue;
            }

            $spec = preg_replace('#/(tcp|udp|sctp)$#', '', (string) $port) ?? '';
            // [ip:]host:container | container; IPv6 hosts are bracketed.
            $spec = preg_replace('/^\[[^\]]*\]:/', '', $spec) ?? $spec;
            $parts = explode(':', $spec);
            $target = end($parts);

            if (is_numeric($target)) {
                $container[] = (int) $target;
            }

            if (count($parts) >= 2) {
                $published[] = (string) $port;
            }
        }

        foreach (is_array($expose) ? $expose : [] as $port) {
            $port = preg_replace('#/(tcp|udp|sctp)$#', '', (string) $port);

            if (is_numeric($port)) {
                $container[] = (int) $port;
            }
        }

        $container = array_values(array_unique($container));
        sort($container);

        return [$container, $published];
    }
}
