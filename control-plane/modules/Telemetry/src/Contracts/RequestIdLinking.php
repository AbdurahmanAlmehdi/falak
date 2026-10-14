<?php

namespace Falak\Telemetry\Contracts;

use Falak\Telemetry\Events\RequestIdLinkingChanged;

/**
 * Whether an organization links access lines to its apps' logs through an edge-stamped X-Request-Id (Observability
 * settings, off by default; docs/plans/network/http.md). The Edge module renders the header routes only for those that
 * do, and re-applies their servers when it changes ({@see RequestIdLinkingChanged}).
 */
interface RequestIdLinking
{
    public function enabled(string $organizationId): bool;
}
