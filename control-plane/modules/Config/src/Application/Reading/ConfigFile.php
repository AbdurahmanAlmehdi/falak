<?php

namespace Falak\Config\Application\Reading;

use Falak\Config\Contracts\ConfigIssue;

/**
 * A read falak.yaml / falak.json: the document in the JSON data model (stdClass maps, lists, scalars), or the
 * reading error; plus where its values are.
 */
final readonly class ConfigFile
{
    public function __construct(
        public string $raw,
        public string $format,
        public mixed $document,
        public ?ConfigIssue $error,
        public Positions $positions,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /** Line and column of an issue: of its source in the file (resolved issues), else of its pointer. */
    public function locate(ConfigIssue $issue, bool $key = false): ConfigIssue
    {
        if ($issue->line !== null) {
            return $issue;
        }

        $pointer = $issue->source ?? $issue->pointer;

        if ($pointer === null) {
            return $issue;
        }

        $at = $this->positions->find($pointer, $key);

        return $at === null ? $issue : $issue->at($at['line'], $at['column']);
    }
}
