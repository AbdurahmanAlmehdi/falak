<?php

namespace Falak\Uptime\Domain\Enums;

enum MonitorSource: string
{
    /** One per site domain, kept in sync with the site's domains. */
    case Auto = 'auto';
    /** Created by a user. */
    case User = 'user';
}
