<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * Fields a project's falak.yaml manages are read-only here: edits that change them answer 422.
 */
trait GuardsManagedFields
{
    /** General settings input => pointer relative to the unit. */
    private const MANAGED_SETTINGS = [
        'runtime' => '/runtime', 'build_mode' => '/build/mode', 'php_version' => '/php', 'node_version' => '/node',
        'root_directory' => '/root', 'web_directory' => '/web_directory', 'container_port' => '/port', 'docker_image' => '/image',
        'dockerfile' => '/build/dockerfile', 'health_check_path' => '/health_path', 'test_domain_enabled' => '/test_domain',
    ];

    private function managedFields(Site $site): ManagedFields
    {
        return app(ManagedResources::class)->managed('site', $site->id);
    }

    /**
     * Refuse a variables edit that changes (or removes) a managed variable, or the exposure when the file sets it.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @param  ?list<string>  $exposedBefore
     * @param  ?list<string>  $exposedAfter
     */
    private function guardVariables(Site $site, array $before, array $after, ?array $exposedBefore = null, ?array $exposedAfter = null, string $field = 'variables'): void
    {
        $managed = $this->managedFields($site);

        if (! $managed->any()) {
            return;
        }

        $changed = [];

        foreach ($managed->under('/env') as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            throw ValidationException::withMessages([$field => $managed->badge().': '.implode(', ', $changed).' '.(count($changed) === 1 ? 'is' : 'are').' set by the file; edit the file to change '.(count($changed) === 1 ? 'it' : 'them').'.']);
        }

        if ($exposedAfter !== null && $managed->covers('/expose')) {
            $a = array_values(array_intersect($exposedBefore ?? [], array_keys($after)));
            $b = array_values(array_intersect($exposedAfter, array_keys($after)));
            sort($a);
            sort($b);

            if ($a !== $b) {
                throw ValidationException::withMessages(['exposed' => $managed->badge().': the exposed variables are set by the file.']);
            }
        }
    }

    /** Pointer of an env key, for tests and payloads. */
    private static function envPointer(string $key): string
    {
        return '/env/'.Pointer::escape($key);
    }
}
