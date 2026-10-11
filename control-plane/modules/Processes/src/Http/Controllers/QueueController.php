<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\Actions\DeleteProcess;
use Falak\Processes\Application\Actions\SaveWorker;
use Falak\Processes\Domain\Models\Worker;
use Falak\Processes\Http\Requests\ProcessRules;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class QueueController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    /** The classic page moved into the canvas panel's Processes tab. */
    public function index(Request $request, string $site): RedirectResponse
    {
        return ProcessesController::toPanel($this->site($request, $site)->id);
    }

    public function store(Request $request, string $site, SaveWorker $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, null, $request->validate(ProcessRules::worker($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Queue worker saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $worker, SaveWorker $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->worker($site, $worker), $request->validate(ProcessRules::worker($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Queue worker updated.');
    }

    public function destroy(Request $request, string $site, string $worker, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->worker($site, $worker));

        return back()->with('success', 'Queue worker removed.');
    }

    private function worker(SiteData $site, string $id): Worker
    {
        $model = Worker::query()->where('site_id', $site->id)->findOrFail(strtolower($id));

        // Managed by the project's falak.yaml: changed through the file only.
        app(ManagedResources::class)->managed('worker', $model->id)->guardWhole('worker', '/workers');

        return $model;
    }
}
