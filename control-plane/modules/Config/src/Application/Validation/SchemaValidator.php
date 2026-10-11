<?php

namespace Falak\Config\Application\Validation;

use Falak\Config\Application\Reading\ConfigFile;
use Falak\Config\ConfigServiceProvider;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\Pointer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Stage 2: contracts/config/v1.schema.json (opis/json-schema). One issue per failing leaf, at the pointer of the value
 * that fails; for unknown or missing properties the object holding them, positioned at the offending key.
 */
final class SchemaValidator
{
    private ?Validator $validator = null;

    /**
     * @return list<ConfigIssue>
     */
    public function validate(mixed $document, ?ConfigFile $file = null, bool $resolved = false): array
    {
        $result = $this->validator()->validate($document, ConfigServiceProvider::SCHEMA_ID);

        if ($result->isValid()) {
            return [];
        }

        $formatter = new ErrorFormatter;
        $out = [];
        $seen = [];
        $leaves = function (ValidationError $error) use (&$leaves, &$out, &$seen, $formatter, $file, $resolved): void {
            if ($error->subErrors() !== []) {
                foreach ($error->subErrors() as $sub) {
                    $leaves($sub);
                }

                return;
            }

            $pointer = Pointer::of(array_map(fn ($t) => (string) $t, $error->data()->fullPath()));
            $message = $formatter->formatErrorMessage($error);

            if (isset($seen[$pointer."\0".$message])) {
                return;
            }

            $seen[$pointer."\0".$message] = true;
            $issue = ConfigIssue::error('schema', $pointer, self::humanize($message));

            if ($file !== null && ! $resolved) {
                // Unknown properties: the position of the first offending key.
                $props = $error->keyword() === 'additionalProperties' ? (array) ($error->args()['properties'] ?? []) : [];
                $at = $props !== [] ? $file->positions->find($pointer.'/'.Pointer::escape((string) $props[0]), true) : null;
                $issue = $at !== null ? $issue->at($at['line'], $at['column']) : $file->locate($issue);
            }

            $out[] = $issue;
        };
        $leaves($result->error());
        usort($out, fn (ConfigIssue $a, ConfigIssue $b) => strcmp((string) $a->pointer, (string) $b->pointer));

        return $out;
    }

    private static function humanize(string $message): string
    {
        return rtrim($message, '.').'.';
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(50);
            $this->validator->resolver()?->registerFile(ConfigServiceProvider::SCHEMA_ID, ConfigServiceProvider::schemaPath());
        }

        return $this->validator;
    }
}
