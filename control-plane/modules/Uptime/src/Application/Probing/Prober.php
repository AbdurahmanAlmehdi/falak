<?php

namespace Falak\Uptime\Application\Probing;

use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Domain\Probing\ProbeTarget;

/**
 * Probes a target once. The runner retries; faked in tests of the runner.
 */
interface Prober
{
    public function probe(ProbeTarget $target): ProbeResult;
}
