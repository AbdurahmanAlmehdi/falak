<?php

namespace Falak\Config\Contracts;

/**
 * What a planned {@see Change} does to a resource or a field.
 */
enum ChangeOp: string
{
    /** The file adds it: the resource (or field) is created. */
    case Create = 'create';

    /** The file changes its value. */
    case Update = 'update';

    /** Gone from the file and created by it: deleted. */
    case Delete = 'delete';

    /** Gone from the file but not deletable by it (a database, a volume, or one the file didn't create): kept, no longer managed. */
    case Detach = 'detach';

    /** Already as the file says (plans list them so a run shows what the file manages). */
    case Noop = 'noop';
}
