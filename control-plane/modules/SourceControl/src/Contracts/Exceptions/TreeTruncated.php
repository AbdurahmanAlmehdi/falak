<?php

namespace Falak\SourceControl\Contracts\Exceptions;

/**
 * A complete file list was asked for and the provider (or Falak's limit) cut it short.
 */
final class TreeTruncated extends SourceControlException
{
    public static function at(string $repository, string $ref): self
    {
        return new self("The file list of {$repository} at ".substr($ref, 0, 12).' is too long to read whole.');
    }
}
