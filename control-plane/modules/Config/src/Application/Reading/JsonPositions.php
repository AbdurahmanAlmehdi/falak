<?php

namespace Falak\Config\Application\Reading;

use Falak\Config\Contracts\Pointer;

/**
 * Line and column of the values of a JSON file by JSON Pointer (a small scanner over the text; the file has already
 * been decoded, so it is valid JSON).
 */
final class JsonPositions implements Positions
{
    private PositionNode $root;

    /** @var list<int> offsets where each line starts */
    private array $lineStarts = [0];

    private string $text;

    public function __construct(string $raw)
    {
        $this->text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $this->root = new PositionNode(1, 1, 1, 1);

        for ($i = 0, $n = strlen($this->text); $i < $n; $i++) {
            if ($this->text[$i] === "\n") {
                $this->lineStarts[] = $i + 1;
            }
        }

        try {
            $pos = 0;
            $this->value($pos, $this->root);
        } catch (\Throwable) {
            // keep what was found
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

    /**
     * @return array{0: int, 1: int}
     */
    private function at(int $offset): array
    {
        $line = 0;

        foreach ($this->lineStarts as $i => $start) {
            if ($start > $offset) {
                break;
            }

            $line = $i;
        }

        return [$line + 1, $offset - $this->lineStarts[$line] + 1];
    }

    private function value(int &$pos, PositionNode $node): void
    {
        $this->space($pos);
        [$node->line, $node->column] = $this->at($pos);
        $ch = $this->text[$pos] ?? '';

        if ($ch === '{') {
            $pos++;

            while (true) {
                $this->space($pos);

                if (($this->text[$pos] ?? '}') === '}') {
                    $pos++;

                    return;
                }

                if ($this->text[$pos] === ',') {
                    $pos++;

                    continue;
                }

                [$keyLine, $keyColumn] = $this->at($pos);
                $start = $pos;
                $this->string($pos);
                $key = (string) json_decode(substr($this->text, $start, $pos - $start));
                $this->space($pos);
                $pos++; // :
                $child = new PositionNode($keyLine, $keyColumn, $keyLine, $keyColumn);
                $this->value($pos, $child);
                $node->children[$key] = $child;
            }
        }

        if ($ch === '[') {
            $pos++;
            $index = 0;

            while (true) {
                $this->space($pos);

                if (($this->text[$pos] ?? ']') === ']') {
                    $pos++;

                    return;
                }

                if ($this->text[$pos] === ',') {
                    $pos++;

                    continue;
                }

                $child = new PositionNode(1, 1, 1, 1);
                $this->value($pos, $child);
                $child->keyLine = $child->line;
                $child->keyColumn = $child->column;
                $node->children[(string) $index++] = $child;
            }
        }

        if ($ch === '"') {
            $this->string($pos);

            return;
        }

        while ($pos < strlen($this->text) && ! in_array($this->text[$pos], [',', '}', ']', ' ', "\n", "\r", "\t"], true)) {
            $pos++;
        }
    }

    private function string(int &$pos): void
    {
        for ($pos++, $n = strlen($this->text); $pos < $n; $pos++) {
            if ($this->text[$pos] === '\\') {
                $pos++;
            } elseif ($this->text[$pos] === '"') {
                $pos++;

                return;
            }
        }
    }

    private function space(int &$pos): void
    {
        while ($pos < strlen($this->text) && in_array($this->text[$pos], [' ', "\n", "\r", "\t"], true)) {
            $pos++;
        }
    }
}
