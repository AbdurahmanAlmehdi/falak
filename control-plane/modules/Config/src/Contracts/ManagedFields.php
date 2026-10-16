<?php

namespace Falak\Config\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * The fields a config file manages on one resource: shown read-only in the dashboard with a "Managed by falak.yaml @
 * abc1234" badge linking to the file at that commit, and refused (422) when an edit changes them.
 */
final readonly class ManagedFields
{
    /**
     * @param  list<string>  $paths  JSON Pointers relative to the unit (/deploy/strategy, /env/APP_ENV, /workers/queue)
     * @param  ?string  $url  the file at that commit on the git provider (null for plain git servers)
     */
    public function __construct(
        public array $paths = [],
        public ?string $commit = null,
        public ?string $file = null,
        public ?string $url = null,
        public ?string $unit = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function any(): bool
    {
        return $this->paths !== [];
    }

    /** The path is managed: itself, an ancestor (a whole worker), or (with $orDescendant) any field under it. */
    public function covers(string $path, bool $orDescendant = false): bool
    {
        foreach ($this->paths as $managed) {
            if ($managed === $path || str_starts_with($path, $managed.'/') || ($orDescendant && str_starts_with($managed, $path.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Managed paths under a prefix, relative to it (e.g. the env keys under /env).
     *
     * @return list<string>
     */
    public function under(string $prefix): array
    {
        $out = [];

        foreach ($this->paths as $managed) {
            if (str_starts_with($managed, $prefix.'/')) {
                $out[] = Pointer::unescape(substr($managed, strlen($prefix) + 1));
            }
        }

        return $out;
    }

    /** The key a named item (a schedule, a daemon) is managed under in a section (`/schedules/<key>`), if any. */
    public function key(string $section): ?string
    {
        $under = $this->under('/'.trim($section, '/'));

        return $under === [] ? null : explode('/', $under[0])[0];
    }

    public function badge(): string
    {
        return 'Managed by '.($this->file ?? 'falak.yaml').($this->commit !== null ? ' @ '.substr($this->commit, 0, 7) : '');
    }

    /**
     * Refuse an edit that changes managed fields: $fields maps each input key to its pointer; an input changes the
     * field when it is present in $input and differs from $current[key].
     *
     * @param  array<string, string>  $fields  input key => pointer relative to the unit
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     *
     * @throws ValidationException
     */
    public function guard(array $fields, array $input, array $current): void
    {
        if (! $this->any()) {
            return;
        }

        $errors = [];

        foreach ($fields as $key => $path) {
            if (! array_key_exists($key, $input) || ! $this->covers($path)) {
                continue;
            }

            if (self::normalize($input[$key]) !== self::normalize($current[$key] ?? null)) {
                $errors[$key] = $this->badge().': edit the file to change it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Refuse any edit of a resource the file manages as a whole (a worker, a domain). */
    public function guardWhole(string $field, string $path): void
    {
        if ($this->covers($path, true)) {
            throw ValidationException::withMessages([$field => $this->badge().': edit the file to change it.']);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * For the dashboard: null when nothing is managed.
     *
     * @return ?array{badge: string, commit: ?string, short: ?string, file: ?string, url: ?string, paths: list<string>}
     */
    public function toArray(): ?array
    {
        if (! $this->any()) {
            return null;
        }

        return [
            'badge' => $this->badge(),
            'commit' => $this->commit,
            'short' => $this->commit !== null ? substr($this->commit, 0, 7) : null,
            'file' => $this->file,
            'url' => $this->url,
            'paths' => $this->paths,
        ];
    }
}
