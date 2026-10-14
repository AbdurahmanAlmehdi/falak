<?php

namespace Falak\Network\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A block stopped being applied because it covers an address that became protected after it was made (a new server,
 * a private network, a member's address, the control plane): the address is reachable again.
 */
final class IpBlockSuspended implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'network.block_suspended';

    public function __construct(
        public string $organizationId,
        public string $blockId,
        public string $cidr,
        public string $reason,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Warning, "Block of {$this->cidr} lifted", $this->reason,
            '/network/blocked', "network.block_suspended:{$this->blockId}", context: ['block_id' => $this->blockId, 'cidr' => $this->cidr]);
    }
}
