<?php

namespace Falak\Insights\Contracts;

enum IssueKind: string
{
    case Exception = 'exception';
    case Performance = 'performance';
    case Heartbeat = 'heartbeat';
    /** A managed database's slow query shape or engine error ({@see DatabaseIssues}). */
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::Exception => 'Exception',
            self::Performance => 'Performance',
            self::Heartbeat => 'Scheduled task',
            self::Database => 'Database',
        };
    }
}
