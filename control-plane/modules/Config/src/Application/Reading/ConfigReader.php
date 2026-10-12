<?php

namespace Falak\Config\Application\Reading;

use DateTimeInterface;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\Pointer;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Stage 1: reading a file with the contract's rules ("Reading the file", pinned by contracts/config/cases/parse):
 * one document, YAML 1.2 booleans and nulls, no timestamps, no custom tags, no infinite numbers, unique keys.
 */
final class ConfigReader
{
    /** Largest file read (SourceControlGateway::MAX_FILE_BYTES). */
    public const MAX_BYTES = 1048576;

    /** `falak.json` is JSON; anything else (falak.yaml, falak.yml) YAML. */
    public static function formatOf(string $path): string
    {
        return str_ends_with(strtolower($path), '.json') ? 'json' : 'yaml';
    }

    public function read(string $raw, string $format = 'yaml'): ConfigFile
    {
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $format = $format === 'json' ? 'json' : 'yaml';
        $positions = $format === 'json' ? new JsonPositions($raw) : new YamlPositions($raw);

        if (strlen($raw) > self::MAX_BYTES) {
            return new ConfigFile($raw, $format, null, ConfigIssue::error('parse', '', 'The file is larger than 1 MiB.'), $positions);
        }

        [$document, $error] = $format === 'json' ? $this->json($raw) : $this->yaml($raw);

        if ($error !== null) {
            $error = $error->line === null && $error->pointer !== null && $error->pointer !== '' ? $this->place($error, $positions) : $error;
        }

        return new ConfigFile($raw, $format, $error === null ? $document : null, $error, $positions);
    }

    private function place(ConfigIssue $issue, Positions $positions): ConfigIssue
    {
        $at = $positions->find((string) $issue->pointer);

        return $at === null ? $issue : $issue->at($at['line'], $at['column']);
    }

    /**
     * @return array{0: mixed, 1: ?ConfigIssue}
     */
    private function json(string $raw): array
    {
        if (trim($raw) === '') {
            return [null, ConfigIssue::error('parse.empty', '', 'The file is empty.')];
        }

        try {
            return [json_decode($raw, false, 512, JSON_THROW_ON_ERROR), null];
        } catch (JsonException $e) {
            return [null, ConfigIssue::error('parse', null, 'Invalid JSON: '.$e->getMessage().'.')];
        }
    }

    /**
     * @return array{0: mixed, 1: ?ConfigIssue}
     */
    private function yaml(string $raw): array
    {
        try {
            $doc = Yaml::parse($raw, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $message = $e->getMessage();
            $code = match (true) {
                str_starts_with($message, 'Duplicate key') => 'parse.duplicate_key',
                str_contains($message, 'Multiple documents') => 'parse.multiple_documents',
                str_contains($message, 'Tags support is not enabled') => 'parse.tag',
                str_contains($message, 'Complex mappings are not supported'), str_contains($message, 'Non-string keys') => 'parse.key',
                default => 'parse',
            };
            $line = $e->getParsedLine() > 0 ? $e->getParsedLine() : null;
            $e->setParsedLine(-1);
            $e->setSnippet('');

            return [null, new ConfigIssue($code, null, rtrim($e->getMessage(), '.').'.', null, $line, null)];
        }

        if ($doc === null) {
            return [null, ConfigIssue::error('parse.empty', '', 'The file is empty.')];
        }

        return [$doc, self::check($doc, '')];
    }

    /**
     * Values YAML reads that JSON has no form for.
     */
    private static function check(mixed $value, string $pointer): ?ConfigIssue
    {
        if ($value instanceof DateTimeInterface) {
            return ConfigIssue::error('parse.timestamp', $pointer, 'This value reads as a date or a timestamp; quote it.');
        }

        if (is_float($value) && (is_infinite($value) || is_nan($value))) {
            return ConfigIssue::error('parse.number', $pointer, 'Infinite and NaN numbers are not allowed.');
        }

        if ($value instanceof stdClass || is_array($value)) {
            foreach ((array) $value as $key => $child) {
                if (($error = self::check($child, $pointer.'/'.Pointer::escape((string) $key))) !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * A document (stdClass maps) as plain arrays.
     */
    public static function plain(mixed $doc): mixed
    {
        return json_decode((string) json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Plain arrays as the JSON data model (stdClass maps): what the schema validator takes. Empty arrays become lists.
     */
    public static function document(mixed $plain): mixed
    {
        return json_decode((string) json_encode($plain, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}
