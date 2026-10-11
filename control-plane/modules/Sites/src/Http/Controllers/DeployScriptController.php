<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Sites\Application\Actions\UpdateDeployScript;
use Falak\Sites\Contracts\DeployScript;
use Falak\Sites\Contracts\DeployScriptSections;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Presets\Preset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DeployScriptController extends Controller
{
    use GuardsManagedFields;
    use PresentsSites;

    /** JSON for the Settings tab's deploy script editor; a browser visit opens that section. */
    public function show(Request $request, Site $site): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'settings', 'deploy');
        }

        $exposed = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->value('exposed');

        return response()->json(['data' => [
            'script' => $site->deploy_script,
            'defaultScript' => Preset::for($site->framework)->deployScript."\n",
            'macros' => collect(DeployScript::MACROS)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'variables' => collect(DeployScript::VARIABLES)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'exposedEnvironment' => array_values(is_string($exposed) ? (array) json_decode($exposed, true) : (array) $exposed),
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
            // Hooks the project's falak.yaml sets (/deploy/hooks/<section>), read-only here.
            'config' => $this->managedFields($site)->toArray(),
        ]]);
    }

    public function update(Request $request, Site $site, UpdateDeployScript $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate(['script' => ['required', 'string', 'max:65535']]);

        $this->guardHooks($site, $data['script']);
        $update($site, $data['script']);

        return back();
    }

    /** A section of the script the file sets (deploy.hooks) can't change here. */
    private function guardHooks(Site $site, string $script): void
    {
        $managed = $this->managedFields($site);
        $sections = $managed->under('/deploy/hooks');

        if ($sections === []) {
            return;
        }

        try {
            $before = DeployScriptSections::split((string) $site->deploy_script);
            $after = DeployScriptSections::split(str_replace("\r\n", "\n", $script));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['script' => $e->getMessage()]);
        }

        foreach ($sections as $section) {
            if (DeployScriptSections::meaningful($before[$section] ?? '') !== DeployScriptSections::meaningful($after[$section] ?? '')) {
                throw ValidationException::withMessages(['script' => $managed->badge().": the {$section} section is set by the file (deploy.hooks.{$section}); edit the file to change it."]);
            }
        }
    }
}
