<?php

namespace Falak\Config\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A config run failed (alert type `config.run.failed`, once per run).
 */
final class ConfigRunFailed implements Alertable
{
    use Dispatchable;

    public const TYPE = 'config.run.failed';

    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $runId,
        public string $environment,
        public string $url,
        public string $rules = '',
        public string $error = '',
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::TYPE, Severity::Warning, "falak.yaml failed to apply to {$this->environment}", "{$this->error}", $this->url,
            dedupKey: "config-run:{$this->runId}:".self::TYPE, context: ['project_id' => $this->projectId, 'run_id' => $this->runId, 'environment' => $this->environment]);
    }
}
