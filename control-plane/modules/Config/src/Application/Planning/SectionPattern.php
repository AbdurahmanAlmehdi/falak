<?php

namespace Falak\Config\Application\Planning;

/**
 * A section pattern of the phase 0 contract ("Sections, owners and phases"): a dotted path where `*` is any unit key
 * and `*(t1|t2)` a unit key of those types, e.g. `units.*(app).workers`, `units.*.env`, `previews`. Inside a unit, a
 * `*` token is any key of a map (`units.*(compose).services.*.limits`).
 */
final readonly class SectionPattern
{
    /**
     * @param  ?list<string>  $types  unit types (null: any) when the pattern is under units
     * @param  list<string>  $path  the path inside the unit (or inside the root key for non-unit sections)
     */
    public function __construct(
        public string $source,
        public string $root,
        public bool $inUnit,
        public ?array $types,
        public array $path,
    ) {}

    public static function parse(string $pattern): self
    {
        $parts = explode('.', $pattern);
        $root = array_shift($parts);

        if ($root !== 'units') {
            return new self($pattern, (string) $root, false, null, $parts);
        }

        $selector = (string) array_shift($parts);
        $types = null;

        if (preg_match('/^\*\(([a-z|]+)\)$/', $selector, $m)) {
            $types = explode('|', $m[1]);
        }

        return new self($pattern, 'units', true, $types, $parts);
    }

    public function selects(?string $type): bool
    {
        return $this->types === null || ($type !== null && in_array($type, $this->types, true));
    }

    /** Whether $other is strictly inside this section (a longer path under it) for some unit type both select. */
    public function contains(self $other): bool
    {
        if ($this->root !== $other->root || count($other->path) <= count($this->path)) {
            return false;
        }

        return self::matches($this->path, array_slice($other->path, 0, count($this->path)));
    }

    /**
     * Whether a pattern path matches a path of the same length (`*` in the pattern matches any one token; a `*` in
     * $path, another pattern's, is only matched by `*`).
     *
     * @param  list<string>  $pattern
     * @param  list<string>  $path
     */
    public static function matches(array $pattern, array $path): bool
    {
        if (count($pattern) !== count($path)) {
            return false;
        }

        foreach ($pattern as $i => $token) {
            if ($token !== '*' && $token !== $path[$i]) {
                return false;
            }
        }

        return true;
    }
}
