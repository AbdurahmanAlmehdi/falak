<?php

namespace Falak\Uptime\Domain\Enums;

enum MonitorStatus: string
{
    /** Never seen up (automatic monitors open no incident until they have). */
    case Pending = 'pending';
    case Up = 'up';
    case Down = 'down';
}
