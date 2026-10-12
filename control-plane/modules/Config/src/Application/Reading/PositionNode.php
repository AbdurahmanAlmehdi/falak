<?php

namespace Falak\Config\Application\Reading;

/**
 * A value's place in a file: its own position and the position of the key (or `-`) that holds it.
 */
final class PositionNode
{
    /** @var array<string, PositionNode> */
    public array $children = [];

    public function __construct(
        public int $line,
        public int $column,
        public int $keyLine,
        public int $keyColumn,
    ) {}
}
