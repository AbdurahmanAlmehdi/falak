<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Plan;
use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Application\Validation\Validation;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Domain\Models\ConfigPullRequest;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Contracts\Exceptions\NoApi;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Pull requests of a repository a project follows: the file is validated and planned against the environments the
 * base branch deploys, the plan is posted as one PR comment (edited on later updates) with a `falak/config` commit
 * status. Nothing is applied. A fork's pull request is planned with the base branch's file, never its own.
 */
final class PlanPullRequest implements ShouldQueue
{
    public const CONTEXT = 'falak/config';

    public function __construct(
        private readonly FileFetcher $files,
        private readonly ConfigReader $reader,
        private readonly PlanFile $plan,
        private readonly ProjectDirectory $projects,
        private readonly SourceControlGateway $gateway,
        private readonly FileCheck $check,
        private readonly ImplicitEnvironment $implicit,
    ) {}

    public function opened(PullRequestOpened $event): void
    {
        $this->handle($event->organizationId, $event->connectionId, $event->pullRequest);
    }

    public function updated(PullRequestUpdated $event): void
    {
        $this->handle($event->organizationId, $event->connectionId, $event->pullRequest);
    }

    private function handle(string $organizationId, string $connectionId, PullRequestData $pr): void
    {
        $sources = ConfigSource::query()->where('organization_id', $organizationId)->where('source_connection_id', $connectionId)->where('enabled', true)->get()
            ->filter(fn (ConfigSource $s) => strcasecmp($s->repository, $pr->repository) === 0);

        foreach ($sources as $source) {
            try {
                $this->comment($source, $pr);
            } catch (NoApi) {
                return;
            } catch (Throwable $e) {
                Log::warning('config: pull request plan failed', ['source' => $source->id, 'number' => $pr->number, 'error' => $e->getMessage()]);
            }
        }
    }

    private function comment(ConfigSource $source, PullRequestData $pr): void
    {
        $record = ConfigPullRequest::query()->firstOrNew(['project_id' => $source->project_id, 'number' => $pr->number], ['organization_id' => $source->organization_id]);

        if ($record->exists && $record->head_sha === $pr->headSha && $record->comment_id !== null) {
            return; // already planned for this head (GitLab and Bitbucket announce other edits too)
        }

        // A fork's file is untrusted: plan with the base branch's file instead.
        $ref = $pr->isFork ? $pr->baseBranch : $pr->headSha;
        [$raw, $problem] = $this->files->fetch($source, $ref);
        $sections = [];
        $failed = false;
        $risky = false;

        if ($raw === null) {
            $sections[] = "**Can't plan:** {$problem}";
            $failed = true;
        } else {
            $file = $this->reader->read($raw, $source->format());
            $environments = $this->environmentsFor($source, $file->document instanceof stdClass ? $file->document : null, $pr->baseBranch);

            if (! $file->ok()) {
                $sections[] = $this->issues([$file->locate($file->error)], $source->path);
                $failed = true;
            } elseif ($environments === []) {
                $validation = $this->check->check($raw, $source->format());

                if (! $validation->valid()) {
                    $sections[] = $this->issues($validation->errors, $source->path);
                    $failed = true;
                } else {
                    $sections[] = "No environment of `{$source->path}` deploys `{$pr->baseBranch}`: nothing to plan.";
                }
            }

            foreach ($environments as $environment) {
                [$validation, $plan] = ($this->plan)($source, $environment, $raw, $source->format(), $pr->headSha);

                if ($plan === null) {
                    $sections[] = "### {$environment->name}\n\n".$this->issues($validation->errors, $source->path);
                    $failed = true;

                    continue;
                }

                $risky = $risky || $plan->changes->isRisky();
                $sections[] = "### {$environment->name}\n\n".$this->planText($plan, $validation, $source->experimental);
            }
        }

        $intro = $pr->isFork
            ? "This pull request comes from a fork: its own `{$source->path}` is never used; this is the plan of the base branch's file."
            : "Plan of `{$source->path}` at `{$pr->shortSha()}`. Nothing is applied until it is merged.";
        $body = "#### Falak config plan\n\n{$intro}\n\n".implode("\n\n", $sections);

        $record->comment_id = $this->gateway->commentOnPullRequest($source->source_connection_id, $source->repository, $pr->number, $body, $record->comment_id);
        $record->head_sha = $pr->headSha;
        $state = $failed || ($risky && $source->experimental) ? 'failure' : 'success';
        $record->state = $state;
        $record->save();

        $description = match (true) {
            $failed => "{$source->path} is invalid",
            $risky && $source->experimental => 'Risky changes would be refused',
            $risky => 'Risky changes need an approval',
            default => "{$source->path} plan is ready",
        };

        $this->gateway->setCommitStatus($source->source_connection_id, $source->repository, $pr->headSha, $state, self::CONTEXT, $description,
            url('/projects/'.$source->project_id.'/settings#config'));
    }

    /**
     * Environments of the project the base branch deploys, per the file.
     *
     * @return list<EnvironmentData>
     */
    private function environmentsFor(ConfigSource $source, ?stdClass $document, string $branch): array
    {
        if (ImplicitEnvironment::applies($document)) {
            $production = $this->implicit->environmentFor($source, $branch);

            return $production !== null ? [$production] : [];
        }

        $slugs = [];

        foreach ((array) ($document?->environments ?? []) as $slug => $env) {
            if ($env instanceof stdClass && ($env->branch ?? null) === $branch) {
                $slugs[] = (string) $slug;
            }
        }

        return array_values(array_filter($this->projects->environments($source->project_id), fn (EnvironmentData $e) => in_array($e->slug, $slugs, true) && ! $e->isPreview));
    }

    /**
     * @param  list<ConfigIssue>  $issues
     */
    private function issues(array $issues, string $path): string
    {
        $lines = array_map(fn (ConfigIssue $i) => '- `'.$i->format($path).'`', array_slice($issues, 0, 30));

        return "**{$path} is invalid:**\n\n".implode("\n", $lines);
    }

    private function planText(Plan $plan, Validation $validation, bool $experimental): string
    {
        $summary = $plan->changes->summary();
        $text = sprintf('%d to create, %d to update, %d to delete, %d to detach, %d unchanged.', $summary['create'], $summary['update'], $summary['delete'], $summary['detach'], $summary['noop']);

        if ($plan->deploy !== []) {
            $text .= ' Deploys: '.implode(', ', array_map(fn ($u) => "`{$u}`", $plan->deploy)).'.';
        }

        // [decision] No env value ever reaches a comment (fork pull requests included): env changes show the key and
        // whether it was added, changed or removed; whole units and items have their env values masked.
        $rows = array_map(function (Change $c) {
            if (EnvMask::isEnvPath($c->path)) {
                $what = match ($c->op->value) {
                    'create' => 'added', 'update' => 'changed', default => 'removed'
                };

                return sprintf('| %s | `%s` | | |%s', $what, $c->path, $c->risky ? ' risky: '.$c->reason.' |' : ' |');
            }

            return sprintf('| %s | `%s` | %s | %s |%s', $c->op->value, $c->path, self::cell(EnvMask::value($c->before, $c->path)), self::cell(EnvMask::value($c->after, $c->path)),
                $c->risky ? ' risky: '.$c->reason.' |' : ' |');
        }, array_slice($plan->changes->actionable(), 0, 50));

        if ($rows !== []) {
            $text .= "\n\n| Change | Path | Before | After | |\n|---|---|---|---|---|\n".implode("\n", $rows);
        }

        if ($plan->changes->isRisky()) {
            $text .= "\n\n".($experimental ? '**Risky changes:** config as code is experimental for this project, so this plan would be refused on merge.' : '**Risky changes:** the run will wait for an approval.');
        }

        if ($validation->warnings !== []) {
            $text .= "\n\nWarnings:\n".implode("\n", array_map(fn (ConfigIssue $i) => '- `'.$i->format().'`', array_slice($validation->warnings, 0, 20)));
        }

        return $text;
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);

        return '`'.str_replace(['|', '`', "\n"], ['\\|', "'", ' '], mb_strimwidth($text, 0, 80, '…')).'`';
    }
}
