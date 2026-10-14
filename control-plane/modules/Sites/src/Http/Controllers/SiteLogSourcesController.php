<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Sites\Application\Actions\UpdateSiteLogSources;
use Falak\Sites\Application\SiteLogSources;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site → Logs → Sources (docs/plans/OBSERVABILITY.md phase 2): files inside a Docker or compose site's containers, and
 * a PHP-FPM site's slow log threshold. Web and API (JSON both).
 */
final class SiteLogSourcesController extends Controller
{
    /**
     * GET /sites/{site}/log-sources
     */
    public function show(Request $request, Site $site, ComposeSites $compose, ComposeInspector $inspector): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json(['data' => $this->present($request, $site, $compose, $inspector)]);
    }

    /**
     * PUT /sites/{site}/log-sources {sources?, slowlog_timeout?}: the whole list of sources; slowlog_timeout in seconds
     * (0 or null = off; new PHP-FPM sites start at the default).
     */
    public function update(Request $request, Site $site, UpdateSiteLogSources $update, ComposeSites $compose, ComposeInspector $inspector): JsonResponse
    {
        $this->authorize('update', $site);
        $request->validate([
            'sources' => ['sometimes', 'nullable', 'array', 'max:'.SiteLogSources::MAX_SOURCES],
            'slowlog_timeout' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.SiteLogSources::MAX_SLOWLOG_TIMEOUT],
        ]);
        $input = $request->only(['sources', 'slowlog_timeout']);

        if (array_key_exists('slowlog_timeout', $input) && $input['slowlog_timeout'] !== null) {
            $input['slowlog_timeout'] = (int) $input['slowlog_timeout'];
        }

        $changed = $update($site, $input, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => [...$this->present($request, $site->refresh(), $compose, $inspector), 'changed' => $changed]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, Site $site, ComposeSites $compose, ComposeInspector $inspector): array
    {
        return [
            'runtime' => $site->runtime->value,
            // Which parts apply: container sources (Docker, compose), the slow log (PHP-FPM).
            'containers' => SiteLogSources::supportsContainerSources($site),
            'php_fpm' => $site->runtime === SiteRuntime::PhpFpm,
            'sources' => $site->log_sources ?? [],
            'compose_services' => $site->runtime === SiteRuntime::Compose ? $inspector->parse($compose->project($site->id) ?? '')->serviceNames() : [],
            'slowlog_timeout' => $site->runtime === SiteRuntime::PhpFpm ? SiteLogSources::slowlogTimeout($site) : null,
            'limits' => [
                'sources' => SiteLogSources::MAX_SOURCES,
                'slowlog_timeout' => SiteLogSources::MAX_SLOWLOG_TIMEOUT,
                'default_slowlog_timeout' => SiteLogSources::DEFAULT_SLOWLOG_TIMEOUT,
                'pattern_bytes' => SiteLogSources::MAX_PATTERN_BYTES,
            ],
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
        ];
    }
}
