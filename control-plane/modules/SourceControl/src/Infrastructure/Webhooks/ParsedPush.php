<?php

namespace Falak\SourceControl\Infrastructure\Webhooks;

use Falak\SourceControl\Contracts\Data\CommitData;

final readonly class ParsedPush
{
    public function __construct(
        public string $branch,
        public CommitData $commit,
        public ?string $pusher,
        public ?string $beforeSha,
        /** @var ?list<string> files the push changed (null: unknown) */
        public ?array $changedPaths = null,
    ) {}
}
