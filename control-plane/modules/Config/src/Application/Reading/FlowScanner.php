<?php

namespace Falak\Config\Application\Reading;

/**
 * Positions inside a YAML flow collection (`{ a: 1, b: [x, y] }`), possibly joined from several lines: $origin maps
 * each character of $text to its line and column in the file.
 */
final class FlowScanner
{
    /**
     * @param  list<array{0: int, 1: int}>  $origin
     */
    public function __construct(private readonly string $text, private readonly array $origin) {}

    public function value(int &$pos, PositionNode $node): void
    {
        $this->skipSpace($pos);

        if ($pos >= strlen($this->text)) {
            return;
        }

        [$node->line, $node->column] = $this->origin[$pos];
        $ch = $this->text[$pos];

        if ($ch === '{') {
            $pos++;
            $this->mapping($pos, $node);
        } elseif ($ch === '[') {
            $pos++;
            $this->sequence($pos, $node);
        } else {
            $this->scalar($pos);
        }
    }

    private function mapping(int &$pos, PositionNode $node): void
    {
        $n = strlen($this->text);

        while ($pos < $n) {
            $this->skipSpace($pos);

            if ($pos >= $n || $this->text[$pos] === '}') {
                $pos++;

                return;
            }

            if ($this->text[$pos] === ',') {
                $pos++;

                continue;
            }

            [$keyLine, $keyColumn] = $this->origin[$pos];
            $key = $this->key($pos);
            $child = new PositionNode($keyLine, $keyColumn, $keyLine, $keyColumn);
            $this->skipSpace($pos);

            if ($pos < $n && $this->text[$pos] === ':') {
                $pos++;
                $this->skipSpace($pos);

                if ($pos < $n && ! in_array($this->text[$pos], [',', '}'], true)) {
                    $this->value($pos, $child);
                }
            }

            $node->children[$key] = $child;
        }
    }

    private function sequence(int &$pos, PositionNode $node): void
    {
        $n = strlen($this->text);
        $index = 0;

        while ($pos < $n) {
            $this->skipSpace($pos);

            if ($pos >= $n || $this->text[$pos] === ']') {
                $pos++;

                return;
            }

            if ($this->text[$pos] === ',') {
                $pos++;

                continue;
            }

            [$line, $column] = $this->origin[$pos];
            $child = new PositionNode($line, $column, $line, $column);
            $this->value($pos, $child);
            $node->children[(string) $index++] = $child;
        }
    }

    private function key(int &$pos): string
    {
        $ch = $this->text[$pos];

        if ($ch === '"' || $ch === "'") {
            $start = $pos;
            $this->scalar($pos);
            $raw = substr($this->text, $start + 1, $pos - $start - 2);

            return $ch === "'" ? str_replace("''", "'", $raw) : (string) json_decode('"'.$raw.'"');
        }

        $start = $pos;
        $n = strlen($this->text);

        while ($pos < $n && ! in_array($this->text[$pos], [',', '}', ']'], true)
            && ! ($this->text[$pos] === ':' && ($pos + 1 >= $n || in_array($this->text[$pos + 1], [' ', ',', '}'], true)))) {
            $pos++;
        }

        return trim(substr($this->text, $start, $pos - $start));
    }

    private function scalar(int &$pos): void
    {
        $n = strlen($this->text);
        $ch = $this->text[$pos];

        if ($ch === '"' || $ch === "'") {
            for ($pos++; $pos < $n; $pos++) {
                if ($ch === '"' && $this->text[$pos] === '\\') {
                    $pos++;

                    continue;
                }

                if ($this->text[$pos] === $ch) {
                    if ($ch === "'" && ($this->text[$pos + 1] ?? '') === "'") {
                        $pos++;

                        continue;
                    }

                    $pos++;

                    return;
                }
            }

            return;
        }

        while ($pos < $n && ! in_array($this->text[$pos], [',', '}', ']'], true)) {
            $pos++;
        }
    }

    private function skipSpace(int &$pos): void
    {
        $n = strlen($this->text);

        while ($pos < $n && ($this->text[$pos] === ' ' || $this->text[$pos] === "\t")) {
            $pos++;
        }
    }
}
