<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Events\PushReceived;
use Falak\SourceControl\Infrastructure\Webhooks\ParsedPush;
use Illuminate\Support\Str;

/**
 * Store verified branch pushes in the push log and announce them ({@see PushReceived} drives push-to-deploy).
 */
final class RecordPushes
{
    /**
     * @param  list<ParsedPush>  $pushes
     * @return list<Push>
     */
    public function __invoke(Connection $connection, string $repository, array $pushes, ?Webhook $webhook = null): array
    {
        $recorded = [];

        foreach ($pushes as $push) {
            if ($push->commit->sha === '') {
                continue;
            }

            $recorded[] = Push::query()->create([
                'organization_id' => $connection->organization_id,
                'connection_id' => $connection->id,
                'webhook_id' => $webhook?->id,
                'repository' => $repository,
                'branch' => Str::limit($push->branch, 250, ''),
                'sha' => Str::limit($push->commit->sha, 64, ''),
                'before_sha' => $push->beforeSha ? Str::limit($push->beforeSha, 64, '') : null,
                'author_name' => $push->commit->authorName ? Str::limit($push->commit->authorName, 250, '') : null,
                'author_email' => $push->commit->authorEmail ? Str::limit($push->commit->authorEmail, 250, '') : null,
                'message' => Str::limit($push->commit->message, 10000),
                'url' => $push->commit->url ? Str::limit($push->commit->url, 1000, '') : null,
                'pusher' => $push->pusher ? Str::limit($push->pusher, 250, '') : null,
                'committed_at' => $push->commit->committedAt,
                'received_at' => now(),
            ]);

            PushReceived::dispatch(
                $connection->organization_id,
                $connection->id,
                $connection->provider->value,
                $repository,
                $push->branch,
                $push->commit,
                $push->pusher,
                $push->beforeSha,
                $push->changedPaths,
            );
        }

        return $recorded;
    }
}
