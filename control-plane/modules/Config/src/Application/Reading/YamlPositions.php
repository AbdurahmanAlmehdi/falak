<?php

namespace Falak\Config\Application\Reading;

use Falak\Config\Contracts\Pointer;

/**
 * Line and column of the values of a YAML file by JSON Pointer. symfony/yaml keeps no positions, so this is a small
 * structural scanner of its own: block mappings and sequences by indentation, `- key: value` items, single and
 * multi-line flow collections, quoted keys, comments, anchors and block scalars. What it can't place (values reached
 * through an alias or a merge key) answers null, which the contract allows.
 */
final class YamlPositions implements Positions
{
    /** @var list<array{0: int, 1: string, 2: int}> indent, content (comment stripped), 1-based line */
    private array $lines = [];

    private PositionNode $root;

    public function __construct(string $raw)
    {
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $this->root = new PositionNode(1, 1, 1, 1);

        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $number => $line) {
            $content = rtrim(self::stripComment($line));
            $trimmed = ltrim($content, ' ');

            if ($trimmed === '' || $trimmed === '---' || $trimmed === '...' || str_starts_with($trimmed, '%')) {
                $this->lines[] = [-1, '', $number + 1];

                continue;
            }

            $this->lines[] = [strlen($content) - strlen($trimmed), $trimmed, $number + 1];
        }

        try {
            $first = $this->next(0);

            if ($first !== null) {
                $this->block($first, $this->lines[$first][0], $this->root);
            }
        } catch (\Throwable) {
            // A file the scanner can't follow keeps what it found so far; the rest answers null.
        }
    }

    public function find(string $pointer, bool $key = false): ?array
    {
        $node = $this->root;

        foreach (Pointer::tokens($pointer) as $token) {
            $node = $node->children[$token] ?? null;

            if ($node === null) {
                return null;
            }
        }

        return $key ? ['line' => $node->keyLine, 'column' => $node->keyColumn] : ['line' => $node->line, 'column' => $node->column];
    }

    /** Index of the next non-blank line at or after $i. */
    private function next(int $i): ?int
    {
        for ($n = count($this->lines); $i < $n; $i++) {
            if ($this->lines[$i][0] >= 0) {
                return $i;
            }
        }

        return null;
    }

    /** Parse the block node whose first line is $i (at $indent) into $node; returns the index after it. */
    private function block(int $i, int $indent, PositionNode $node): int
    {
        if (self::isSequenceItem($this->lines[$i][1])) {
            return $this->sequence($i, $indent, $node);
        }

        return $this->mapping($i, $indent, $node);
    }

    private function mapping(int $i, int $indent, PositionNode $node): int
    {
        while (($i = $this->next($i)) !== null && $this->lines[$i][0] === $indent && ! self::isSequenceItem($this->lines[$i][1])) {
            [, $text, $line] = $this->lines[$i];
            $entry = self::splitKey($text);

            if ($entry === null) {
                return $i + 1; // not a mapping entry (e.g. a bare scalar document)
            }

            [$key, $valueOffset] = $entry;
            $child = new PositionNode($line, $indent + 1, $line, $indent + 1);
            $node->children[$key] = $child;
            $i = $this->value($i, $indent, $indent + $valueOffset, substr($text, $valueOffset), $child, true);
        }

        return $i ?? count($this->lines);
    }

    private function sequence(int $i, int $indent, PositionNode $node): int
    {
        $index = 0;

        while (($i = $this->next($i)) !== null && $this->lines[$i][0] === $indent && self::isSequenceItem($this->lines[$i][1])) {
            [, $text, $line] = $this->lines[$i];
            $rest = (string) substr($text, 1);
            $pad = strlen($rest) - strlen(ltrim($rest, ' '));
            $rest = ltrim($rest, ' ');
            $column = $indent + 1 + 1 + $pad;
            $child = new PositionNode($line, $column, $line, $indent + 1);
            $node->children[(string) $index++] = $child;

            if ($rest !== '' && ! in_array($rest[0], ['{', '['], true) && self::splitKey($rest) !== null) {
                // `- key: value`: a mapping whose first entry shares the item's line; read it as if indented there.
                $this->lines[$i] = [$column - 1, $rest, $line];
                $i = $this->mapping($i, $column - 1, $child);

                continue;
            }

            $i = $this->value($i, $indent, $column - 1, $rest, $child, false);
        }

        return $i ?? count($this->lines);
    }

    /**
     * The value of a mapping entry or sequence item that starts at 0-based column $offset of line $i.
     */
    private function value(int $i, int $indent, int $offset, string $text, PositionNode $node, bool $inMapping): int
    {
        $line = $this->lines[$i][2];
        $trimmed = ltrim($text, ' ');
        $offset += strlen($text) - strlen($trimmed);
        $text = $trimmed;

        // Anchors and tags before the value.
        while ($text !== '' && ($text[0] === '&' || $text[0] === '!')) {
            $space = strpos($text, ' ');

            if ($space === false) {
                $text = '';
                break;
            }

            $after = ltrim(substr($text, $space));
            $offset += strlen($text) - strlen($after);
            $text = $after;
        }

        if ($text === '') {
            // The value is the block below (a nested mapping or sequence, possibly at the same indent for a list).
            $next = $this->next($i + 1);

            if ($next !== null && ($this->lines[$next][0] > $indent || ($inMapping && $this->lines[$next][0] === $indent && self::isSequenceItem($this->lines[$next][1])))) {
                return $this->block($next, $this->lines[$next][0], $node);
            }

            return $i + 1;
        }

        $node->line = $line;
        $node->column = $offset + 1;

        if ($text[0] === '|' || $text[0] === '>') {
            // A block scalar: skip its lines (more indented than the entry, or blank).
            $j = $i + 1;

            for ($n = count($this->lines); $j < $n; $j++) {
                if ($this->lines[$j][0] >= 0 && $this->lines[$j][0] <= $indent) {
                    break;
                }
            }

            return $j;
        }

        if ($text[0] === '{' || $text[0] === '[') {
            return $this->flow($i, $offset, $node);
        }

        return $i + 1;
    }

    /**
     * A flow collection starting at column $offset of line $i, possibly over several lines.
     */
    private function flow(int $i, int $offset, PositionNode $node): int
    {
        // Join lines until the brackets balance, remembering where each character came from.
        $text = '';
        $origin = [];
        $depth = 0;
        $j = $i;
        $start = $offset - $this->lines[$i][0];

        for ($n = count($this->lines); $j < $n; $j++) {
            [$indent, $content, $line] = $this->lines[$j];

            if ($indent < 0) {
                continue;
            }

            $from = $j === $i ? $start : 0;
            $quote = null;

            for ($k = $from, $len = strlen($content); $k < $len; $k++) {
                $ch = $content[$k];
                $text .= $ch;
                $origin[] = [$line, $indent + $k + 1];

                if ($quote !== null) {
                    if ($ch === $quote) {
                        $quote = null;
                    }
                } elseif ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                } elseif ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                }
            }

            $text .= ' ';
            $origin[] = [$line, $indent + strlen($content) + 1];

            if ($depth <= 0) {
                break;
            }
        }

        $pos = 0;
        (new FlowScanner($text, $origin))->value($pos, $node);

        return $j + 1;
    }

    private static function isSequenceItem(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    /**
     * A mapping entry's key and the offset of the text after `key:`; null when the text isn't one.
     *
     * @return ?array{0: string, 1: int}
     */
    public static function splitKey(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        if ($text[0] === '"' || $text[0] === "'") {
            $quote = $text[0];
            $end = 1;

            while (($end = strpos($text, $quote, $end)) !== false) {
                if ($quote === "'" && ($text[$end + 1] ?? '') === "'") {
                    $end += 2;

                    continue;
                }

                if ($quote === '"' && $text[$end - 1] === '\\') {
                    $end++;

                    continue;
                }

                break;
            }

            if ($end === false) {
                return null;
            }

            $after = ltrim(substr($text, $end + 1), ' ');

            if (! str_starts_with($after, ':')) {
                return null;
            }

            $raw = substr($text, 1, $end - 1);
            $key = $quote === "'" ? str_replace("''", "'", $raw) : (string) json_decode('"'.$raw.'"');

            return [$key, strlen($text) - strlen($after) + 1];
        }

        if (in_array($text[0], ['{', '[', '&', '*', '!', '|', '>', '-'], true) && ! preg_match('/^-[^ ]/', $text)) {
            return null;
        }

        if (! preg_match('/^(.+?):(?: |$)/', $text, $m)) {
            return null;
        }

        return [rtrim($m[1]), strlen($m[1]) + 1];
    }

    /** A line without its comment (a `#` at the start or after a space, outside quotes). */
    private static function stripComment(string $line): string
    {
        $quote = null;

        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $ch = $line[$i];

            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($ch === '"' || $ch === "'") && ($i === 0 || in_array($line[$i - 1], [' ', '{', '[', ',', ':', '-'], true))) {
                $quote = $ch;
            } elseif ($ch === '#' && ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t")) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }
}
