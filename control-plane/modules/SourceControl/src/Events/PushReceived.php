<?php

namespace Falak\SourceControl\Events;

use Falak\SourceControl\Contracts\Data\CommitData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified push webhook for a branch (tag pushes and branch deletions are not announced).
 */
final class PushReceived
{
    use Dispatchable;

    /**
     * @param  ?list<string>  $changedPaths  files the push added, modified or removed (null: the payload doesn't say, e.g.
     *                                       Bitbucket, or a push too large for the payload to list every commit)
     */
    public function __construct(
        public string $organizationId,
        public string $connectionId,
        public string $provider,
        public string $repository,
        public string $branch,
        public CommitData $commit,
        public ?string $pusher = null,
        public ?string $beforeSha = null,
        public ?array $changedPaths = null,
    ) {}
}
