<?php

namespace Falak\Config\Tests\Support;

use DateTimeInterface;
use Falak\Config\ConfigServiceProvider;
use JsonException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The shared falak.yaml cases (contracts/config/cases, README.md there), read with the contract's rules ("Reading the
 * file" in the phase 0 contract) and validated with opis/json-schema. agent/internal/configspec runs the same cases.
 */
final class ConfigCases
{
    private static ?Validator $validator = null;

    public static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    public static function dir(string $kind): string
    {
        return self::root()."/contracts/config/cases/{$kind}";
    }

    /**
     * The case files of a directory (.yaml, .yml and .json, not the .expect.json files), by file name.
     *
     * @return array<string, string>
     */
    public static function inputs(string $kind): array
    {
        $out = [];

        foreach (glob(self::dir($kind).'/*') ?: [] as $path) {
            if (str_ends_with($path, '.expect.json') || ! in_array(pathinfo($path, PATHINFO_EXTENSION), ['yaml', 'yml', 'json'], true)) {
                continue;
            }

            $out[basename($path)] = $path;
        }

        ksort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function expect(string $path): array
    {
        $file = preg_replace('/\.(ya?ml|json)$/', '.expect.json', $path);

        return (array) json_decode((string) file_get_contents((string) $file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Parse a file into the JSON data model (stdClass maps, lists, scalars).
     *
     * @return array{0: mixed, 1: ?array{code: string, pointer: ?string}} the document, or the parse error
     */
    public static function parse(string $raw, string $format): array
    {
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if ($format === 'json') {
            if (trim($raw) === '') {
                return [null, ['code' => 'parse.empty', 'pointer' => '']];
            }

            try {
                return [json_decode($raw, false, 512, JSON_THROW_ON_ERROR), null];
            } catch (JsonException) {
                return [null, ['code' => 'parse', 'pointer' => null]];
            }
        }

        try {
            $doc = Yaml::parse($raw, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $message = $e->getMessage();
            $code = match (true) {
                str_starts_with($message, 'Duplicate key') => 'parse.duplicate_key',
                str_contains($message, 'Multiple documents') => 'parse.multiple_documents',
                str_contains($message, 'Tags support is not enabled') => 'parse.tag',
                default => 'parse',
            };

            return [null, ['code' => $code, 'pointer' => null]];
        }

        if ($doc === null) {
            return [null, ['code' => 'parse.empty', 'pointer' => '']];
        }

        $error = self::check($doc, '');

        return $error === null ? [$doc, null] : [null, $error];
    }

    /**
     * Values YAML reads that JSON has no form for.
     *
     * @return ?array{code: string, pointer: string}
     */
    private static function check(mixed $value, string $pointer): ?array
    {
        if ($value instanceof DateTimeInterface) {
            return ['code' => 'parse.timestamp', 'pointer' => $pointer];
        }

        if (is_float($value) && (is_infinite($value) || is_nan($value))) {
            return ['code' => 'parse.number', 'pointer' => $pointer];
        }

        if ($value instanceof stdClass || is_array($value)) {
            foreach ((array) $value as $key => $child) {
                if (($error = self::check($child, $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key))) !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * Schema errors, one per failing leaf, at the pointer of the value that fails (unknown or missing properties:
     * the object holding them).
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function validate(mixed $doc): array
    {
        $result = self::validator()->validate($doc, ConfigServiceProvider::SCHEMA_ID);

        if ($result->isValid()) {
            return [];
        }

        $out = [];
        $leaves = function (ValidationError $error) use (&$leaves, &$out): void {
            if ($error->subErrors() === []) {
                $out[] = [
                    'pointer' => implode('', array_map(fn ($t) => '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $t), $error->data()->fullPath())),
                    'message' => $error->message(),
                ];

                return;
            }

            foreach ($error->subErrors() as $sub) {
                $leaves($sub);
            }
        };
        $leaves($result->error());

        return $out;
    }

    /**
     * A decoded document (stdClass maps) as plain arrays, for comparisons.
     */
    public static function plain(mixed $doc): mixed
    {
        return json_decode((string) json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * An expect file's JSON (arrays) as the JSON data model the validator takes.
     */
    public static function document(mixed $plain): mixed
    {
        return json_decode((string) json_encode($plain, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->setMaxErrors(50);
            self::$validator->resolver()?->registerFile(ConfigServiceProvider::SCHEMA_ID, self::root().'/'.ConfigServiceProvider::SCHEMA);
        }

        return self::$validator;
    }
}
