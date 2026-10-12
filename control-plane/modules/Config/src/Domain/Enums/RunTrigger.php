<?php

namespace Falak\Config\Domain\Enums;

enum RunTrigger: string
{
    case Push = 'push';
    case PullRequest = 'pr';
    case Cli = 'cli';
    case Manual = 'manual';
    case Rollback = 'rollback';
}
