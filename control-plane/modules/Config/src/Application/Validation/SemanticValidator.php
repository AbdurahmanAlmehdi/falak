<?php

namespace Falak\Config\Application\Validation;

use Falak\Config\Application\Planning\Reconcilers;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Secrets;

/**
 * Stage 4, per environment: names the file uses must exist for the project (servers attached to it, secrets in the
 * unit's scope chain, canvas services behind external references), then each reconciler's own checks
 * ({@see ValidatesConfig}), then the fields no reconciler supports yet (warnings).
 */
final class SemanticValidator
{
    public function __construct(
        private readonly Reconcilers $reconcilers,
        private readonly ProjectDirectory $projects,
        private readonly Secrets $secrets,
    ) {}

    /**
     * @param  array<string, mixed>  $resolved
     * @param  list<ConfigIssue>  $warnings  stage 3's warnings (reference.external becomes name.unit when there is no such service)
     * @return array{0: list<ConfigIssue>, 1: list<ConfigIssue>}
     */
    public function check(ConfigScope $scope, array $resolved, array $warnings): array
    {
        $errors = [];
        $out = [];
        $env = $resolved['environments'][$scope->environment] ?? [];

        foreach ((array) ($env['servers'] ?? []) as $i => $name) {
            if ($scope->serverId((string) $name) === null) {
                $errors[] = self::server('/environments/'.Pointer::escape($scope->environment).'/servers/'.$i, (string) $name);
            }
        }

        $services = [];

        foreach ($this->projects->servicesIn($scope->environmentId) as $service) {
            $services[$service->name] = $service;
        }

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            $base = '/units/'.Pointer::escape((string) $unit);

            foreach ((array) ($body['servers'] ?? []) as $i => $name) {
                if ($scope->serverId((string) $name) === null) {
                    $errors[] = self::server("{$base}/servers/{$i}", (string) $name);
                }
            }

            $names = [];
            $where = [];

            foreach ((array) ($body['env'] ?? []) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                preg_match_all(VariableReferences::PATTERN, $value, $matches, PREG_SET_ORDER);

                foreach ($matches as $m) {
                    if (strtolower(trim($m[1])) === VariableReferences::SECRETS) {
                        $names[] = $m[2];
                        $where[$m[2]][] = "{$base}/env/".Pointer::escape((string) $key);
                    }
                }
            }

            if ($names !== []) {
                $service = $services[(string) $unit] ?? null;
                $checked = $this->secrets->check(new ScopeChain($scope->organizationId, $scope->projectId, $scope->environmentId, $service?->id), array_values(array_unique($names)));

                foreach ($checked->errors as $name => $reason) {
                    foreach ($where[$name] ?? [] as $pointer) {
                        $errors[] = ConfigIssue::error('name.secret', $pointer, "The secret {$name} doesn't exist for this unit (service, environment, project or organization).");
                    }
                }
            }
        }

        foreach ($warnings as $warning) {
            if ($warning->code !== 'reference.external' || $warning->pointer === null) {
                $out[] = $warning;

                continue;
            }

            $value = (string) (self::at($resolved, $warning->pointer) ?? '');
            preg_match_all(VariableReferences::PATTERN, $value, $matches, PREG_SET_ORDER);
            $missing = [];

            foreach ($matches as $m) {
                $name = trim($m[1]);

                if (strtolower($name) !== VariableReferences::SECRETS && ! isset($resolved['units'][$name]) && ! isset($services[$name])) {
                    $missing[] = $name;
                }
            }

            if ($missing !== []) {
                $errors[] = ConfigIssue::error('name.unit', $warning->pointer, 'No unit or service named "'.implode('", "', $missing).'" in this environment.', $warning->source);
            } else {
                $out[] = $warning;
            }
        }

        foreach ($this->reconcilers->all() as $reconciler) {
            if ($reconciler instanceof ValidatesConfig) {
                foreach ($reconciler->validate($scope, $resolved) as $issue) {
                    if ($issue->isError()) {
                        $errors[] = $issue;
                    } else {
                        $out[] = $issue;
                    }
                }
            }
        }

        foreach ($this->reconcilers->sections()->unsupported($resolved) as $issue) {
            $out[] = $issue;
        }

        return [$errors, $out];
    }

    private static function server(string $pointer, string $name): ConfigIssue
    {
        return ConfigIssue::error('name.server', $pointer, "No server named \"{$name}\" is attached to this project (a server hosting one of its services, or one an admin allows in Project settings → Config as code).");
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function at(array $document, string $pointer): mixed
    {
        $cur = $document;

        foreach (Pointer::tokens($pointer) as $token) {
            if (! is_array($cur) || ! array_key_exists($token, $cur)) {
                return null;
            }

            $cur = $cur[$token];
        }

        return $cur;
    }
}
