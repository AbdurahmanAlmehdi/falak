<?php

namespace Falak\Config\Http\Controllers\Api;

use Falak\Config\Application\Drift\DriftChecker;
use Falak\Config\Domain\Models\ConfigDrift;
use Falak\Config\Http\Controllers\ResolvesProjects;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drift of an environment against its last applied file (API and dashboard).
 */
final class DriftApiController extends Controller
{
    use ResolvesProjects;

    public function show(Request $request, string $project): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $environment = $this->environment($data, (string) $request->query('environment', ''));
        $drift = ConfigDrift::query()->where('environment_id', $environment->id)->first()
            ?? new ConfigDrift(['status' => ConfigDrift::UNKNOWN]);

        return response()->json(['data' => DriftChecker::present($drift, $environment)]);
    }

    public function check(Request $request, string $project, DriftChecker $checker): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $environment = $this->environment($data, (string) $request->validate(['environment' => ['required', 'string', 'max:64']])['environment']);

        return response()->json(['data' => DriftChecker::present($checker->check($environment), $environment)]);
    }
}
