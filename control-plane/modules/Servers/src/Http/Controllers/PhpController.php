<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Servers\Application\Actions\InstallPhpVersion;
use Falak\Servers\Application\Actions\RemovePhpVersion;
use Falak\Servers\Application\Actions\SetDefaultPhpVersion;
use Falak\Servers\Application\Actions\UpdatePhpSettings;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PhpController extends Controller
{
    public function store(Request $request, Server $server, InstallPhpVersion $install): RedirectResponse
    {
        $this->install($request, $server, $install);

        return back();
    }

    /** `POST /api/v1/servers/{server}/php`: the install runs on the agent; poll the server for its PHP versions. */
    public function storeApi(Request $request, Server $server, InstallPhpVersion $install): JsonResponse
    {
        $php = $this->install($request, $server, $install);

        return response()->json(['data' => ['version' => $php->version, 'status' => $php->status->value, 'command_id' => $php->command_id]], 202);
    }

    private function install(Request $request, Server $server, InstallPhpVersion $install): PhpVersion
    {
        $this->authorize('update', $server);

        $data = $request->validate(['version' => ['required', 'string', Rule::in((array) config('servers.php_versions'))]]);

        return $install($server, $data['version']);
    }

    public function default(Server $server, string $version, SetDefaultPhpVersion $setDefault): RedirectResponse
    {
        $this->authorize('update', $server);
        $setDefault($this->version($server, $version));

        return back();
    }

    public function settings(Request $request, Server $server, string $version, UpdatePhpSettings $update): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'ini' => ['present', 'array', 'max:100'],
            'ini.*' => ['nullable'],
            'fpm' => ['required', 'array'],
            'fpm.pm' => ['required', Rule::in(['dynamic', 'static', 'ondemand'])],
            'fpm.max_children' => ['required', 'integer', 'min:1', 'max:2000'],
            'fpm.start_servers' => ['required', 'integer', 'min:1', 'lte:fpm.max_children'],
            'fpm.min_spare_servers' => ['required', 'integer', 'min:1', 'lte:fpm.max_spare_servers'],
            'fpm.max_spare_servers' => ['required', 'integer', 'min:1', 'lte:fpm.max_children'],
            'fpm.max_requests' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $ini = [];

        foreach ($data['ini'] as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_.]+$/', $key) || ! (is_scalar($value))) {
                return back()->withErrors(['ini' => "Invalid php.ini directive [{$key}]."]);
            }

            $ini[$key] = is_bool($value) || is_int($value) ? $value : (string) $value;
        }

        /** @var array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int} $fpm */
        $fpm = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $data['fpm']);

        $update($this->version($server, $version), $ini, $fpm);

        return back();
    }

    public function destroy(Server $server, string $version, RemovePhpVersion $remove): RedirectResponse
    {
        $this->authorize('update', $server);
        $remove($this->version($server, $version));

        return back();
    }

    private function version(Server $server, string $version): PhpVersion
    {
        return $server->phpVersions()->where('version', $version)->firstOrFail();
    }
}
