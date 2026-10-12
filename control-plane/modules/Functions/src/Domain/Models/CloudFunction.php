<?php

namespace Falak\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The code side of a function site: its runtime, scaling and limits. Code lives in immutable versions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $runtime
 * @property string $entrypoint
 * @property int $min_instances
 * @property int $max_instances
 * @property int $concurrency
 * @property int $idle_timeout_s
 * @property int $memory_mb
 * @property float $cpus
 * @property int $request_timeout_s
 * @property ?list<string> $allow_cidrs
 * @property ?string $root a falak.yaml unit's `root`: the repository folder its code comes from (null: the repository root, or code written in the editor)
 */
class CloudFunction extends Model
{
    use HasUlids;

    protected $table = 'functions_functions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_instances' => 'integer',
            'max_instances' => 'integer',
            'concurrency' => 'integer',
            'idle_timeout_s' => 'integer',
            'memory_mb' => 'integer',
            'cpus' => 'float',
            'request_timeout_s' => 'integer',
            'allow_cidrs' => 'array',
        ];
    }

    /** @return HasMany<FunctionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FunctionVersion::class, 'function_id')->orderByDesc('number');
    }

    public function head(): ?FunctionVersion
    {
        return FunctionVersion::query()->where('function_id', $this->id)->orderByDesc('number')->first();
    }

    /**
     * Who may call it, as fn.release.apply `access` (empty when the function is public).
     *
     * @return array{api_key_hashes?: list<string>, allow_cidrs?: list<string>}
     */
    public function access(): array
    {
        return array_filter([
            'api_key_hashes' => FunctionApiKey::query()->where('function_id', $this->id)->orderBy('created_at')->pluck('hash')->all(),
            'allow_cidrs' => array_values((array) ($this->allow_cidrs ?? [])),
        ]);
    }

    /**
     * @return array{min_instances: int, max_instances: int, concurrency: int, idle_timeout_s: int}
     */
    public function scaling(): array
    {
        return [
            'min_instances' => $this->min_instances,
            'max_instances' => max($this->min_instances, $this->max_instances, 1),
            'concurrency' => $this->concurrency,
            'idle_timeout_s' => $this->idle_timeout_s,
        ];
    }

    /**
     * @return array{memory_bytes: int, cpus: float, pids: int, request_timeout_s: int, start_timeout_s: int}
     */
    public function limits(): array
    {
        return [
            'memory_bytes' => $this->memory_mb * 1024 * 1024,
            'cpus' => round($this->cpus, 2),
            'pids' => (int) config('functions.pids', 256),
            'request_timeout_s' => $this->request_timeout_s,
            'start_timeout_s' => (int) config('functions.start_timeout_s', 30),
        ];
    }
}
