<?php

namespace Falak\Config\Application\Validation;

use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use stdClass;

/**
 * The four validation stages of the phase 0 contract: read, schema, resolve (per environment), semantic (with a
 * scope: the organization's servers, secrets, canvas services and each reconciler's own checks). Each stage stops the
 * next on an error. Every issue gets the line and column of its source in the file.
 */
final class FileCheck
{
    public function __construct(
        private readonly ConfigReader $reader,
        private readonly SchemaValidator $schema,
        private readonly Resolver $resolver,
        private readonly SemanticValidator $semantic,
    ) {}

    /**
     * @param  ?string  $environment  null: stages 1-3 for every environment of the file, no stage 4
     * @param  ?\Closure(array<string, mixed>): ConfigScope  $scope  builds the stage 4 scope from the resolved file
     */
    public function check(string $raw, string $format = 'yaml', ?string $environment = null, ?\Closure $scope = null): Validation
    {
        $file = $this->reader->read($raw, $format);

        if (! $file->ok()) {
            return new Validation($file, [], null, [$file->error], []);
        }

        $document = $file->document;
        $errors = $this->schema->validate($document, $file);

        if ($errors !== [] || ! $document instanceof stdClass) {
            return new Validation($file, [], null, $errors !== [] ? $errors : [ConfigIssue::error('schema', '', 'The file must be a mapping.')], []);
        }

        $environments = array_map('strval', array_keys((array) ($document->environments ?? new stdClass)));
        $errors = [];
        $warnings = [];
        $resolved = null;

        foreach ($environment !== null ? [$environment] : $environments as $env) {
            [$doc, $envErrors, $envWarnings] = $this->resolver->resolve($document, $env);

            if ($doc !== null && $envErrors === []) {
                foreach ($this->schema->validate($doc, null, true) as $issue) {
                    $envErrors[] = $issue;
                }
            }

            $errors = self::union($errors, $envErrors);
            $warnings = self::union($warnings, $envWarnings);

            if ($env === $environment && $doc !== null) {
                $resolved = ConfigReader::plain($doc);
            }
        }

        if ($errors === [] && $environment !== null && $scope !== null && $resolved !== null) {
            [$semanticErrors, $semanticWarnings] = $this->semantic->check($scope($resolved), $resolved, $warnings);
            $errors = $semanticErrors;
            $warnings = $semanticWarnings;
        }

        return new Validation(
            $file,
            $environments,
            $errors === [] ? $resolved : ($resolved !== null && self::onlyStage4($errors) ? $resolved : null),
            array_map(fn (ConfigIssue $i) => $file->locate($i), $errors),
            array_map(fn (ConfigIssue $i) => $file->locate($i), $warnings),
        );
    }

    /**
     * @param  list<ConfigIssue>  $errors
     */
    private static function onlyStage4(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error->code, 'schema') || str_starts_with($error->code, 'reference.') || str_starts_with($error->code, 'environment.') || str_starts_with($error->code, 'override.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Issues of several environments, once each (by code, pointer and source).
     *
     * @param  list<ConfigIssue>  $a
     * @param  list<ConfigIssue>  $b
     * @return list<ConfigIssue>
     */
    private static function union(array $a, array $b): array
    {
        $seen = [];

        foreach ($a as $issue) {
            $seen[$issue->code."\0".$issue->pointer."\0".$issue->source] = true;
        }

        foreach ($b as $issue) {
            if (! isset($seen[$k = $issue->code."\0".$issue->pointer."\0".$issue->source])) {
                $seen[$k] = true;
                $a[] = $issue;
            }
        }

        return $a;
    }
}
