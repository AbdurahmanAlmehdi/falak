<?php

namespace Falak\Config\Application;

use Falak\Config\Application\Planning\Reconcilers;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Sites\Contracts\SiteDirectory;
use Symfony\Component\Yaml\Yaml;

/**
 * `falak config export`: today's state of an environment as a file (every reconciler's current(), pruned to its
 * sections), plus the environment with its `branch`. Defaults are left out by the reconcilers.
 */
final class Exporter
{
    public function __construct(
        private readonly Reconcilers $reconcilers,
        private readonly ScopeFactory $scopes,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(EnvironmentData $environment, ?ConfigSource $source, bool $withValues = true): array
    {
        $applied = ConfigRun::query()->where('environment_id', $environment->id)->where('status', RunStatus::Applied)->latest('finished_at')->first();
        $branch = $applied?->resolved['environments'][$environment->slug]['branch'] ?? null;
        $scope = $this->scopes->make($environment, $source, $applied?->resolved);
        // Before a first apply: the branch the environment's sites deploy the source's repository from, so pushing the
        // export applies it.
        $branch ??= $source === null ? null : collect($this->sites->findMany(array_column(array_filter($scope->services, fn (array $s) => $s['kind'] === 'site'), 'ref_id')))
            ->first(fn ($site) => $site->sourceConnectionId === $source->source_connection_id && strcasecmp((string) $site->repository, $source->repository) === 0 && $site->branch !== null)?->branch;
        $units = [];
        $previews = null;

        foreach ($this->reconcilers->all() as $reconciler) {
            $current = $this->reconcilers->sections()->prune($reconciler, self::typed($reconciler->current($scope)));
            $previews ??= is_array($current['previews'] ?? null) ? $current['previews'] : null;

            foreach ((array) ($current['units'] ?? []) as $unit => $body) {
                $units[$unit] = array_replace_recursive($units[$unit] ?? [], (array) $body);
            }
        }

        if (! $withValues) {
            // [decision] Without sites.env.view an export keeps references only: literal env values (of the unit, its
            // workers and daemons) are left out, so planning it changes nothing.
            foreach ($units as $unit => $body) {
                if (isset($body['env'])) {
                    $units[$unit]['env'] = array_filter((array) $body['env'], fn ($v) => is_string($v) && str_contains($v, '${{'));
                }

                foreach (['workers', 'daemons'] as $kind) {
                    foreach ((array) ($body[$kind] ?? []) as $key => $item) {
                        unset($units[$unit][$kind][$key]['env']);
                    }
                }
            }
        }

        foreach ($units as $unit => $body) {
            // type first, as people write it
            $units[$unit] = ['type' => $body['type'] ?? 'app', ...array_diff_key($body, ['type' => true])];
        }

        $env = array_filter(['branch' => $branch, 'production' => $environment->isProduction ? true : null], fn ($v) => $v !== null);

        // The previews block belongs to the run of its base environment (production's when it names none).
        $ownsPreviews = $previews !== null && (isset($previews['base']) ? $previews['base'] === $environment->slug : $environment->isProduction);

        return [
            '$schema' => 'https://falak.sh/schema/v1.json',
            'version' => 1,
            'units' => $units,
            'environments' => [$environment->slug => $env],
            ...($ownsPreviews ? ['previews' => $previews] : []),
        ];
    }

    public function render(array $document, string $format): string
    {
        $model = Reading\ConfigReader::document(self::compact($document));

        if ($format === 'json') {
            return json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
        }

        return Yaml::dump($model, 10, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function typed(array $document): array
    {
        foreach ((array) ($document['units'] ?? []) as $unit => $body) {
            if (is_array($body) && ! isset($body['type'])) {
                $document['units'][$unit]['type'] = 'app';
            }
        }

        return $document;
    }

    /** Drop empty maps the file wouldn't write (an environment with nothing set stays as `{}`). */
    private static function compact(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $k => $v) {
            $v = self::compact($v, $depth + 1);

            if (is_array($v) && $v === [] && $depth !== 2) {
                continue;
            }

            $out[$k] = $v;
        }

        return $out;
    }
}
