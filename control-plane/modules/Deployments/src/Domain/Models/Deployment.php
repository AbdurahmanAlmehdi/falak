<?php

namespace Falak\Deployments\Domain\Models;

use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One deployment of a site: a persisted state machine driven by its steps.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $site_slug
 * @property int $number
 * @property Trigger $trigger
 * @property DeploymentStatus $status
 * @property ?string $phase
 * @property ?Strategy $strategy
 * @property ?string $branch
 * @property ?string $commit
 * @property ?string $commit_message
 * @property ?string $commit_author
 * @property ?string $build_id
 * @property ?string $release_id
 * @property ?string $target_release_id
 * @property ?string $previous_release_id
 * @property bool $rolling_back
 * @property bool $rolled_back a failed deployment's servers were reverted, or a live release was rolled back by its watch
 * @property ?string $rolled_back_reason why the watch after it went live rolled it back (or only alerted)
 * @property ?Carbon $rolled_back_at
 * @property ?string $auto_rollback_of a rollback deployment started by the watch of this deployment
 * @property bool $cancel_requested
 * @property ?array<string, string> $variables
 * @property ?array<string, mixed> $settings
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $waiting_since
 * @property ?string $waiting_reason
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property Collection<int, DeploymentTarget> $targets
 * @property Collection<int, DeploymentStep> $steps
 * @property ?ReleaseWatch $watch
 * @property ?ReleaseWatch $watch
 */
class Deployment extends Model
{
    use HasUlids;

    protected $table = 'deployments_deployments';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['variables'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => Trigger::class,
            'status' => DeploymentStatus::class,
            'strategy' => Strategy::class,
            'number' => 'integer',
            'rolling_back' => 'boolean',
            'rolled_back' => 'boolean',
            'cancel_requested' => 'boolean',
            // Deploy hook FALAK_VAR_* values may carry secrets.
            'variables' => SealedArray::class,
            'settings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'waiting_since' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DeploymentTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class)->orderBy('position');
    }

    /**
     * @return HasMany<DeploymentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class)->orderBy('position');
    }

    /**
     * The watch window after its release went live (sites that watch releases).
     *
     * @return HasOne<ReleaseWatch, $this>
     */
    public function watch(): HasOne
    {
        return $this->hasOne(ReleaseWatch::class, 'deployment_id');
    }

    /**
     * @return HasMany<OutputLine, $this>
     */
    public function output(): HasMany
    {
        return $this->hasMany(OutputLine::class)->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * Where a person looks at this deployment: the Deploy view in the site's canvas panel (or the legacy site
     * page while the site is not placed in a project). Absolute, for the CLI, alerts and API clients.
     */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').self::path($this->site_id, $this->id);
    }

    /** Relative URL of a site's deployments (one deployment when $deploymentId is given). */
    public static function path(string $siteId, ?string $deploymentId = null): string
    {
        $panel = app(ProjectDirectory::class)->serviceUrl(ServiceKind::Site, $siteId, 'deployments');

        if ($panel !== null) {
            return $deploymentId !== null ? "{$panel}/{$deploymentId}" : $panel;
        }

        return $deploymentId !== null ? "/sites/{$siteId}/deployments/{$deploymentId}" : "/sites/{$siteId}/deployments";
    }

    public function shortCommit(): ?string
    {
        return $this->commit !== null ? substr($this->commit, 0, 7) : null;
    }

    /** The site's deployment of $commit that hasn't finished yet (queued, waiting, building or deploying). */
    public static function activeFor(string $siteId, string $commit): ?self
    {
        return self::query()->where('site_id', $siteId)->where('commit', $commit)->where('trigger', '!=', Trigger::Rollback)
            ->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::Waiting, DeploymentStatus::Building, DeploymentStatus::Deploying])
            ->latest()->first();
    }
}
