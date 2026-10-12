<?php

namespace Falak\Config\Application\Reading;

/**
 * Where the values of a file are, by JSON Pointer (1-based line and column).
 */
interface Positions
{
    /**
     * @param  bool  $key  the position of the key holding the value (unknown properties point at their key)
     * @return ?array{line: int, column: int}
     */
    public function find(string $pointer, bool $key = false): ?array;
}
