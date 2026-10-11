<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\Actions\DeleteProcess;
use Falak\Processes\Application\Actions\SaveDaemon;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Http\Requests\ProcessRules;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DaemonController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    /** The classic page moved into the canvas panel's Processes tab. */
    public function index(Request $request, string $site): RedirectResponse
    {
        return ProcessesController::toPanel($this->site($request, $site)->id);
    }

    public function store(Request $request, string $site, SaveDaemon $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $data = $request->validate(ProcessRules::daemon($site));

        // A name falak.yaml uses is its daemon's identity: another one by that name would be ambiguous.
        foreach (Daemon::query()->where('site_id', $site->id)->where('name', trim((string) $data['name']))->pluck('id') as $id) {
            app(ManagedResources::class)->managed('daemon', $id)->guardWhole('name', '/daemons');
        }

        $save($site, null, $data, $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Daemon saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $daemon, SaveDaemon $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->daemon($site, $daemon), $request->validate(ProcessRules::daemon($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Daemon updated.');
    }

    public function destroy(Request $request, string $site, string $daemon, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->daemon($site, $daemon));

        return back()->with('success', 'Daemon removed.');
    }

    private function daemon(SiteData $site, string $id): Daemon
    {
        $model = Daemon::query()->where('site_id', $site->id)->findOrFail(strtolower($id));

        // Managed by the project's falak.yaml: changed through the file only.
        app(ManagedResources::class)->managed('daemon', $model->id)->guardWhole('daemon', '/daemons');

        return $model;
    }
}
