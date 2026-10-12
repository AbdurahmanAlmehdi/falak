<?php

namespace Falak\Config\Domain\Models;

use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One plan (and apply) of a project's file for one environment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property ?string $environment_id
 * @property string $environment
 * @property ?string $commit
 * @property ?string $branch
 * @property RunTrigger $trigger
 * @property RunStatus $status
 * @property string $file
 * @property string $format
 * @property ?array<string, mixed> $resolved
 * @property ?array{changes: list<array<string, mixed>>, summary: array<string, int>, risky: bool, deploy: list<string>} $plan
 * @property ?list<array{unit: string, site_id: ?string, deployment_id: ?string, error?: ?string}> $deploy
 * @property ?list<array<string, mixed>> $errors
 * @property ?string $error
 * @property bool $allow_destroy
 * @property ?string $requested_by
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $approval_expires_at
 * @property ?Carbon $phase_a_at when phase A committed (drift's baseline)
 * @property ?list<array{path: string, rule: ?string}> $approved_risky
 * @property ?string $approval_comment
 * @property ?string $rejected_by
 * @property ?Carbon $rejected_at
 * @property ?string $cancel_reason superseded | rejected | expired | source_disabled | user
 * @property Carbon $created_at
 */
class ConfigRun extends Model
{
    use HasUlids;

    protected $table = 'config_runs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => RunTrigger::class,
            'status' => RunStatus::class,
            'resolved' => 'array',
            'plan' => 'array',
            'deploy' => 'array',
            'errors' => 'array',
            'allow_destroy' => 'boolean',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'approval_expires_at' => 'datetime',
            'phase_a_at' => 'datetime',
            'approved_risky' => 'array',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * **[decision]** An environment's baseline: its latest run whose phase A committed (the file the environment is
     * configured with, even if a deployment failed afterwards), or an applied run from before `phase_a_at` existed.
     * Who deploys on push, what a push without a readable file falls back to, what is released, "Apply now"'s branch
     * and a preview's base all read it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBaseline(Builder $query): void
    {
        $query->whereNotNull('resolved')->where(fn (Builder $q) => $q->whereNotNull('phase_a_at')->orWhere('status', RunStatus::Applied))
            ->latest('created_at')->orderByDesc('id');
    }

    public function changes(): ChangeSet
    {
        return ChangeSet::fromArray($this->plan['changes'] ?? []);
    }

    public function shortCommit(): ?string
    {
        return $this->commit !== null ? substr($this->commit, 0, 7) : null;
    }

    /**
     * The resolved file's unit (what Release.config stores).
     *
     * @return ?array<string, mixed>
     */
    public function unit(string $unit): ?array
    {
        $value = $this->resolved['units'][$unit] ?? null;

        return is_array($value) ? $value : null;
    }
}
