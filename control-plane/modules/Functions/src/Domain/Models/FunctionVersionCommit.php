<?php

namespace Falak\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A repository commit whose files under the function's root are this version (functions of a falak.yaml).
 *
 * @property string $id
 * @property string $function_id
 * @property string $commit
 * @property string $version_id
 */
class FunctionVersionCommit extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'functions_version_commits';

    protected $guarded = [];
}
