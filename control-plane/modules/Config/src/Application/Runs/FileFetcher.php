<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Domain\Models\ConfigSource;
use Falak\SourceControl\Contracts\Exceptions\NoApi;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;

/**
 * Reads a source's file at a commit through {@see SourceControlGateway::file()}.
 */
final class FileFetcher
{
    public function __construct(private readonly SourceControlGateway $gateway) {}

    /**
     * @return array{0: ?string, 1: ?string} the file (null when missing or unreadable) and why it couldn't be read
     */
    public function fetch(ConfigSource $source, string $ref): array
    {
        try {
            $raw = $this->gateway->file($source->source_connection_id, $source->repository, $ref, ltrim($source->path, '/'));
        } catch (NoApi) {
            // [decision] The plan has falak-builder read the file from its checkout for plain git servers; until it
            // does, those repositories can't follow a file (connecting one is refused, see ConnectSource).
            return [null, "{$source->path} can't be read from a plain git server yet."];
        } catch (SourceControlException $e) {
            return [null, "{$source->path} couldn't be read: {$e->getMessage()}"];
        }

        if ($raw === null) {
            return [null, "{$source->path} doesn't exist at ".substr($ref, 0, 12).'.'];
        }

        return [$raw, null];
    }
}
