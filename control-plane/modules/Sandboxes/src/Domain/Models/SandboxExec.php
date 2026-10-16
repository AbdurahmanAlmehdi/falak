<?php

namespace Falak\Sandboxes\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One exec, file write or file delete in a sandbox: the audit trail (the command is redacted; output is not kept).
 *
 * @property string $id
 * @property string $sandbox_id
 * @property string $actor_type user | token
 * @property ?string $actor_id
 * @property ?string $actor
 * @property string $cmd
 * @property ?string $cwd
 * @property ?int $exit_code
 * @property ?int $duration_ms
 * @property int $stdout_bytes
 * @property int $stderr_bytes
 * @property bool $timed_out
 * @property ?string $error why the call failed (no exit code then)
 * @property Carbon $started_at
 */
class SandboxExec extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'sandboxes_execs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'stdout_bytes' => 'integer',
            'stderr_bytes' => 'integer',
            'timed_out' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'cmd' => $this->cmd,
            'cwd' => $this->cwd,
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'timed_out' => $this->timed_out,
            'error' => $this->error,
            'actor' => $this->actor,
            'actor_type' => $this->actor_type,
            'started_at' => $this->started_at->toIso8601String(),
        ];
    }
}
