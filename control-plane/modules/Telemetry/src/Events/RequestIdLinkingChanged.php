<?php

namespace Falak\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An organization turned request ID linking on or off: its edges render (or drop) the X-Request-Id routes.
 */
final class RequestIdLinkingChanged
{
    use Dispatchable;

    public function __construct(public readonly string $organizationId, public readonly bool $enabled) {}
}
