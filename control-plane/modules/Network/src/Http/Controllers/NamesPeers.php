<?php

namespace Falak\Network\Http\Controllers;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Servers\Contracts\ServerDirectory;

/** Peer names of the agent's reports and metric labels resolved to what the organization calls them. */
trait NamesPeers
{
    /** @var array<string, array{label: string, url: ?string}|null> */
    private array $names = [];

    /**
     * A peer name (server:<id>, db:<id>, site:<slug>[/<service>], host:<process>, host) as a label and a link; null when
     * it names nothing of this organization.
     *
     * @return array{label: string, url: ?string}|null
     */
    private function name(?string $name): ?array
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (array_key_exists($name, $this->names)) {
            return $this->names[$name];
        }

        $organizationId = app(CurrentOrganization::class)->requireId();
        [$kind, $ref] = array_pad(explode(':', $name, 2), 2, '');

        return $this->names[$name] = match ($kind) {
            'server' => ($s = app(ServerDirectory::class)->find($ref)) && $s->organizationId === $organizationId ? ['label' => $s->name, 'url' => "/servers/{$s->id}"] : null,
            'db' => ($d = app(DatabaseDirectory::class)->instance($ref)) && $d->organizationId === $organizationId ? ['label' => $d->name, 'url' => null] : null,
            'site' => ['label' => $ref, 'url' => null],
            'host' => ['label' => $ref === '' ? 'this server' : $ref, 'url' => null],
            default => null,
        };
    }

    /**
     * The names of the falak_net_peer series of the charts (series as MetricSeries::toArray()), for the chart legends:
     * peer label => name. Unknown peers are left out (the UI shows their bucket label).
     *
     * @param  array<string, list<array{labels: array<string, string>}>>  $charts
     * @return array<string, string>
     */
    private function peerLabels(array $charts): array
    {
        $labels = [];

        foreach ($charts as $series) {
            foreach ($series as $s) {
                $peer = $s['labels']['falak_net_peer'] ?? null;

                if ($peer !== null && ! isset($labels[$peer]) && ($named = $this->name($peer)) !== null) {
                    $labels[$peer] = $named['label'];
                }
            }
        }

        return $labels;
    }
}
