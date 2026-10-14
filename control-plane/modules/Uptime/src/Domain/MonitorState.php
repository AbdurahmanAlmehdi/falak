<?php

namespace Falak\Uptime\Domain;

use Falak\Uptime\Domain\Enums\MonitorStatus;

/**
 * A monitor's up / down state machine (pure). A check (already confirmed by a second attempt) moves it:
 *
 *  - pending → up on the first success (the monitor is armed); an unarmed automatic monitor stays pending through
 *    failures (a site that never answered is not an outage), a user monitor goes down like an up one;
 *  - up → down after $confirmAfter consecutive failed checks (the incident opens);
 *  - down → up after $recoverAfter consecutive successful checks (the incident closes).
 */
final readonly class MonitorState
{
    public const OPENED = 'opened';

    public const CLOSED = 'closed';

    public const ARMED = 'armed';

    public function __construct(
        public MonitorStatus $status,
        public bool $armed,
        public int $failures,
        public int $successes,
    ) {}

    /**
     * @return array{0: self, 1: ?string} the next state and its transition (OPENED, CLOSED, ARMED or null)
     */
    public function next(bool $ok, bool $automatic, int $confirmAfter, int $recoverAfter): array
    {
        $confirmAfter = max(1, $confirmAfter);
        $recoverAfter = max(1, $recoverAfter);

        if ($ok) {
            return match ($this->status) {
                MonitorStatus::Pending => [new self(MonitorStatus::Up, true, 0, 1), self::ARMED],
                MonitorStatus::Up => [new self(MonitorStatus::Up, true, 0, $this->successes + 1), null],
                MonitorStatus::Down => $this->successes + 1 >= $recoverAfter
                    ? [new self(MonitorStatus::Up, true, 0, $this->successes + 1), self::CLOSED]
                    : [new self(MonitorStatus::Down, true, $this->failures, $this->successes + 1), null],
            };
        }

        $failures = $this->failures + 1;

        if ($this->status === MonitorStatus::Down) {
            return [new self(MonitorStatus::Down, $this->armed, $failures, 0), null];
        }

        if ($this->status === MonitorStatus::Pending && $automatic && ! $this->armed) {
            return [new self(MonitorStatus::Pending, false, $failures, 0), null];
        }

        return $failures >= $confirmAfter
            ? [new self(MonitorStatus::Down, $this->armed, $failures, 0), self::OPENED]
            : [new self($this->status, $this->armed, $failures, 0), null];
    }
}
