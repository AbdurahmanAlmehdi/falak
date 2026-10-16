<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Config\Contracts\ItemKey;
use Falak\Config\Contracts\ManagedResources;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\Actions\DeleteProcess;
use Falak\Processes\Application\Actions\SaveSchedule;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Http\Requests\ProcessRules;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ScheduleController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    /** The classic page moved into the canvas panel's Processes tab. */
    public function index(Request $request, string $site): RedirectResponse
    {
        return ProcessesController::toPanel($this->site($request, $site)->id);
    }

    public function store(Request $request, string $site, SaveSchedule $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $data = $request->validate(ProcessRules::schedule());

        // A name falak.yaml uses is its schedule's identity: another one by that name (or reading as its key) would be
        // ambiguous.
        $name = trim((string) $data['name']);

        foreach (Schedule::query()->where('site_id', $site->id)->get(['id', 'name']) as $row) {
            $managed = app(ManagedResources::class)->managed('schedule', $row->id);

            if ($row->name === $name || $managed->key('schedules') === ItemKey::of($name)) {
                $managed->guardWhole('name', '/schedules');
            }
        }

        $save($site, null, $data, $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Scheduled job saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $schedule, SaveSchedule $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->schedule($site, $schedule), $request->validate(ProcessRules::schedule()), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Scheduled job updated.');
    }

    public function destroy(Request $request, string $site, string $schedule, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->schedule($site, $schedule));

        return back()->with('success', 'Scheduled job removed.');
    }

    private function schedule(SiteData $site, string $id): Schedule
    {
        $model = Schedule::query()->where('site_id', $site->id)->findOrFail(strtolower($id));

        // Managed by the project's falak.yaml: changed through the file only.
        app(ManagedResources::class)->managed('schedule', $model->id)->guardWhole('schedule', '/schedules');

        return $model;
    }
}
