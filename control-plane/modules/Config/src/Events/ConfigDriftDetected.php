<?php

namespace Falak\Config\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An environment no longer matches the falak.yaml last applied to it (alert type `config.drift`, once per drift
 * signature).
 */
final class ConfigDriftDetected implements Alertable
{
    use Dispatchable;

    public const TYPE = 'config.drift';

    /**
     * @param  list<string>  $paths  the drifted paths (no values)
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $environment,
        public string $signature,
        public array $paths,
        public string $url,
    ) {}

    public function toAlert(): AlertData
    {
        $count = count($this->paths);
        $list = implode(', ', array_slice($this->paths, 0, 5)).($count > 5 ? ', …' : '');

        return new AlertData($this->organizationId, self::TYPE, Severity::Warning, "{$this->environment} drifted from falak.yaml",
            "{$count} ".($count === 1 ? 'field differs' : 'fields differ')." from the file last applied: {$list}.", $this->url,
            dedupKey: "config-drift:{$this->environmentId}:{$this->signature}", context: ['project_id' => $this->projectId, 'environment' => $this->environment]);
    }
}
