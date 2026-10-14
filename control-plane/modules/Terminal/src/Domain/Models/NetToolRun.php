<?php

namespace Falak\Terminal\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One network tool run (docs/plans/network/tools.md): a net.tool command from a server or a container.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $server_id
 * @property string $server_name
 * @property string $target_type server|site|database
 * @property ?string $target_id
 * @property ?string $target_name
 * @property ?string $container docker:<name> | compose:<service> (display)
 * @property string $tool ping|traceroute|dns|http|tcp
 * @property array<string, mixed> $input the validated arguments
 * @property ?string $command_id
 * @property string $status running|succeeded|failed|timed_out
 * @property ?int $exit_code
 * @property ?string $error
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class NetToolRun extends Model
{
    use HasUlids;

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const TIMED_OUT = 'timed_out';

    protected $table = 'terminal_net_tool_runs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input' => 'array', 'exit_code' => 'integer', 'finished_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanel(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'input' => $this->input,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_name' => $this->target_name,
            'server_id' => $this->server_id,
            'server_name' => $this->server_name,
            'container' => $this->container,
            'command_id' => $this->command_id,
            'status' => $this->status,
            'exit_code' => $this->exit_code,
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
