<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Domain\Models\ConfigPullRequest;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Http\Presenters;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A push run waiting for an approval tells the pull request it came from: Falak's plan comment of that pull request
 * (found by `#<number>` in the commit message — GitHub's merge and squash titles — or by its head sha) gets "Waiting
 * for an approval" with the run's link.
 */
final class ApprovalNotice
{
    public function __construct(private readonly SourceControlGateway $gateway) {}

    public function post(ConfigRun $run, ?string $message): void
    {
        $source = ConfigSource::query()->where('project_id', $run->project_id)->first();

        if ($source === null) {
            return;
        }

        $numbers = [];

        if ($message !== null && preg_match_all('/#(\d+)\b/', $message, $m)) {
            $numbers = array_map('intval', $m[1]);
        }

        $records = ConfigPullRequest::query()->where('project_id', $run->project_id)->whereNotNull('comment_id')
            ->where(fn ($q) => $q->whereIn('number', $numbers ?: [0])->orWhere('head_sha', (string) $run->commit))->get();

        foreach ($records as $record) {
            $base = (string) preg_replace('/\n\n---\n\*\*Waiting for an approval[\s\S]*$/', '', (string) $record->body);
            $risky = implode(', ', array_unique(array_filter(array_column((array) ($run->plan['changes'] ?? []), 'rule'))));
            $body = $base."\n\n---\n**Waiting for an approval** in {$run->environment}: the merged plan has risky changes ({$risky}). "
                .'[Review the run]('.Presenters::run($run)['url'].').';

            try {
                $record->comment_id = $this->gateway->commentOnPullRequest($source->source_connection_id, $source->repository, $record->number, $body, $record->comment_id);
                $record->body = $body;
                $record->save();
            } catch (Throwable $e) {
                Log::info('config: approval notice not posted', ['run' => $run->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
