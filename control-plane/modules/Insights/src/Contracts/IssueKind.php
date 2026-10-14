<?php

namespace Falak\Insights\Contracts;

enum IssueKind: string
{
    case Exception = 'exception';
    case Performance = 'performance';
    case Heartbeat = 'heartbeat';
    /** A managed database's slow query shape or engine error ({@see DatabaseIssues}). */
    case Database = 'database';
    /**
     * A security finding: on a server (an exposed port, brute force: {@see SecurityIssues}) or in a site's traffic
     * (scanners and probes: {@see SecurityFindings}). One kind, each source writes it through its own contract.
     */
    case Security = 'security';
    /** An uptime monitor down, or its certificate chain broken ({@see UptimeIssues}). */
    case Uptime = 'uptime';

    public function label(): string
    {
        return match ($this) {
            self::Exception => 'Exception',
            self::Performance => 'Performance',
            self::Heartbeat => 'Scheduled task',
            self::Database => 'Database',
            self::Security => 'Security',
            self::Uptime => 'Uptime',
        };
    }
}
