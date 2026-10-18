<?php

namespace Falak\Recovery\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One site moving to another server ("Move to another server"), step by step like {@see ServerRecovery}.
 *
 * Steps, in order: check (the target can take it), add_target (the new server joins the site's deployment group as a
 * member and gets a deploy; the old one stays leader, traffic unchanged), databases (containers on the old server
 * only this site uses: backed up, recreated on the new server, restored; shared ones skipped), volumes (moved; a
 * compose stack's are copied for its next `up` there), cutover (the new server becomes leader, the old one leaves,
 * redeploy, then managed DNS verified, generated names renamed, the others listed to change by hand), cleanup (old
 * copies removed once the site runs on the new server; backups kept). Every step before the cutover is additive: a
 * failure there leaves the site serving from the old server.
 *
 * Status: queued (a "Move all sites" waiting its turn) → running → succeeded | failed | cancelled; a failed move
 * that can't be cancelled any more can be abandoned (only marked, so the site can be moved again).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $site_name
 * @property string $from_server_id
 * @property string $from_server_name
 * @property string $target_server_id
 * @property string $target_server_name
 * @property ?string $storage_provider_id
 * @property ?string $batch_id
 * @property string $status
 * @property ?string $current_step
 * @property list<array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}> $steps
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class SiteMove extends Model
{
    use HasUlids;

    public const STEPS = [
        'check' => 'Check the new server',
        'add_target' => 'Add the new server',
        'databases' => 'Move databases',
        'volumes' => 'Move volumes',
        'cutover' => 'Cut over',
        'cleanup' => 'Clean up',
    ];

    /** Item states that let a step finish. */
    public const DONE = ['succeeded', 'skipped', 'manual'];

    /** A move that holds the site (one at a time per site). */
    public const ACTIVE = ['queued', 'running', 'failed'];

    protected $table = 'recovery_site_moves';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /** @return array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}|null */
    public function step(string $key): ?array
    {
        foreach ($this->steps as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }

    /** Past the point of no return: the new server leads the site (the cutover switched it). */
    public function switched(): bool
    {
        return collect($this->step('cutover')['items'] ?? [])->contains(fn (array $item) => ($item['switched'] ?? false) === true);
    }

    /**
     * Something moved already, which cancelling can't undo: a database relocated (past its backup), a volume being
     * copied or copied (a failed volume move put everything back by itself).
     */
    public function movedData(): bool
    {
        return collect($this->step('databases')['items'] ?? [])->contains(fn (array $item) => in_array($item['phase'] ?? null, ['create', 'restore'], true))
            || collect($this->step('volumes')['items'] ?? [])->contains(fn (array $item) => in_array($item['state'], ['running', 'succeeded'], true));
    }
}
