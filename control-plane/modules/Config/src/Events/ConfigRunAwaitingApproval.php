<?php

namespace Falak\Config\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A config run waits for an approval (alert type `config.run.awaiting_approval`, each time the run starts waiting).
 */
final class ConfigRunAwaitingApproval implements Alertable
{
    use Dispatchable;

    public const TYPE = 'config.run.awaiting_approval';

    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $runId,
        public string $environment,
        public string $url,
        public string $rules = '',
        public string $error = '',
        public string $planHash = '',
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::TYPE, Severity::Warning, "falak.yaml for {$this->environment} waits for an approval", "Its plan has risky changes: {$this->rules}.", $this->url,
            dedupKey: "config-run:{$this->runId}:".self::TYPE.":{$this->planHash}", context: ['project_id' => $this->projectId, 'run_id' => $this->runId, 'environment' => $this->environment]);
    }
}
