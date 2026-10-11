<?php

namespace Falak\Volumes\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Http\Controller;
use Falak\Volumes\Application\Actions\AttachVolume;
use Falak\Volumes\Application\Actions\BrowseVolume;
use Falak\Volumes\Application\Actions\CloneVolume;
use Falak\Volumes\Application\Actions\CreateVolume;
use Falak\Volumes\Application\Actions\DeleteVolume;
use Falak\Volumes\Application\Actions\DetachVolume;
use Falak\Volumes\Application\Actions\DownloadFromVolume;
use Falak\Volumes\Application\Actions\MoveVolume;
use Falak\Volumes\Application\Actions\ResizeVolume;
use Falak\Volumes\Application\Actions\UpdateVolume;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Policies\VolumePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VolumeController extends Controller
{
    use GuardsConfigManaged;
    use PresentsVolumes;

    private const LABELS = ['labels' => ['sometimes', 'array', 'max:32'], 'labels.*' => ['string', 'max:256']];

    public function __construct(private readonly OrganizationAccess $access) {}

    public function store(Request $request, CurrentOrganization $organization, CreateVolume $create): RedirectResponse|JsonResponse
    {
        $organizationId = $organization->requireId();
        $this->access->authorize($request->user(), $organizationId, VolumePolicy::MANAGE);

        $data = $request->validate([
            'server_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:63'],
            'kind' => ['required', Rule::in([VolumeKind::Docker->value, VolumeKind::Sized->value, VolumeKind::Bind->value])],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'host_path' => ['nullable', 'string', 'max:1024'],
            'protected' => ['sometimes', 'boolean'],
            ...self::LABELS,
        ]);
        $kind = VolumeKind::from($data['kind']);

        // Bind volumes are the host's own files.
        if ($kind === VolumeKind::Bind && ! $this->admin($request, $organizationId)) {
            throw ValidationException::withMessages(['kind' => 'Only admins can mount host paths.']);
        }

        $volume = $create($organizationId, strtolower($data['server_id']), $data['name'], $kind, isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            $data['host_path'] ?? null, self::labels($data), (bool) ($data['protected'] ?? false), $request->user()?->getAuthIdentifier());

        return $this->done($request, ['id' => $volume->id, 'url' => "/volumes/{$volume->id}"], 201);
    }

    public function update(Request $request, Volume $volume, UpdateVolume $update): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $data = $request->validate(['protected' => ['sometimes', 'boolean'], ...self::LABELS]);
        $this->guardVolume($volume, ['protected' => '/protected'], $data, ['protected' => $volume->protected]);

        // Protection guards data against deletion: only admins lift it.
        if ($volume->protected && array_key_exists('protected', $data) && ! $data['protected'] && ! $this->admin($request, $volume->organization_id)) {
            throw ValidationException::withMessages(['protected' => 'Only admins can turn protection off.']);
        }

        $update($volume, array_key_exists('protected', $data) ? (bool) $data['protected'] : null, array_key_exists('labels', $data) ? self::labels($data) : null);

        return $this->done($request);
    }

    /**
     * DELETE /volumes/{volume} {confirm: the volume's name}.
     */
    public function destroy(Request $request, Volume $volume, DeleteVolume $delete): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$volume->name])]], ['confirm.in' => 'Type the volume name to confirm.']);
        $this->guardManaged('volume', $volume->id, 'confirm', 'this volume');

        $delete($volume, $request->user()?->getAuthIdentifier());

        // From the volume's page: back to its server's volumes.
        return $request->header('X-Inertia') !== null && $volume->server_id !== null ? redirect("/servers/{$volume->server_id}/volumes") : $this->done($request);
    }

    public function attach(Request $request, Volume $volume, AttachVolume $attach): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);

        // A host path mounted into a container exposes the host's files: admins only.
        if ($volume->kind === VolumeKind::Bind && ! $this->admin($request, $volume->organization_id)) {
            throw ValidationException::withMessages(['volume' => 'Only admins can mount host paths.']);
        }

        $data = $request->validate([
            'site_id' => ['required', 'string', 'size:26'],
            'mount_path' => ['required', 'string', 'max:1024'],
            'read_only' => ['sometimes', 'boolean'],
        ]);

        $attachment = $attach($volume, $data['site_id'], $data['mount_path'], (bool) ($data['read_only'] ?? false), $request->user()?->getAuthIdentifier());

        return $this->done($request, ['id' => $attachment->id], 201);
    }

    public function detach(Request $request, Attachment $attachment, DetachVolume $detach): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $attachment->volume);
        $this->guardManaged('volume', $attachment->volume_id, 'attachment', 'where this volume is mounted');

        $detach($attachment, $request->user()?->getAuthIdentifier());

        return $this->done($request);
    }

    public function resize(Request $request, Volume $volume, ResizeVolume $resize): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $data = $request->validate(['size_bytes' => ['required', 'integer', 'min:1']]);
        $this->guardVolume($volume, ['size_bytes' => '/size'], $data, ['size_bytes' => $volume->size_limit_bytes]);

        $operation = $resize($volume, (int) $data['size_bytes'], $request->user()?->getAuthIdentifier());

        return $this->done($request, $this->presentOperation($operation));
    }

    public function clone(Request $request, Volume $volume, CloneVolume $clone): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $data = $request->validate([
            'server_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:63'],
            'consistency' => ['sometimes', Rule::enum(Consistency::class)],
            'storage_provider_id' => ['nullable', 'string', 'size:26'],
        ]);

        $operation = $clone($volume, strtolower($data['server_id']), $data['name'], Consistency::from($data['consistency'] ?? 'none'),
            $data['storage_provider_id'] ?? null, $request->user()?->getAuthIdentifier());

        return $this->done($request, $this->presentOperation($operation));
    }

    /**
     * POST /volumes/{volume}/move {server_id, storage_provider_id, confirm: the volume's name}. Its services are down
     * from the archive until they run on the target.
     */
    public function move(Request $request, Volume $volume, MoveVolume $move): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $data = $request->validate([
            'server_id' => ['required', 'string', 'size:26'],
            'storage_provider_id' => ['required', 'string', 'size:26'],
            'confirm' => ['required', 'string', Rule::in([$volume->name])],
        ], ['confirm.in' => 'Type the volume name to confirm.']);
        $this->guardVolume($volume, ['server_id' => '/server'], $data, ['server_id' => $volume->server_id]);

        $operation = $move($volume, strtolower($data['server_id']), $data['storage_provider_id'], $request->user()?->getAuthIdentifier());

        return $this->done($request, $this->presentOperation($operation));
    }

    /**
     * GET /volumes/{volume}/browse?path=&search=&offset= (JSON): one page of a directory (volumes.browse; audited).
     */
    public function browse(Request $request, Volume $volume, BrowseVolume $browse): JsonResponse
    {
        $this->authorize('browse', $volume);
        $this->hostPaths($request, $volume);
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'search' => ['nullable', 'string', 'max:128'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $volume->loadMissing('attachments');

        return response()->json(['data' => $browse($volume, (string) ($data['path'] ?? ''), $data['search'] ?? null, (int) ($data['offset'] ?? 0))]);
    }

    /**
     * POST /volumes/{volume}/downloads {path, storage_provider_id} (JSON): the agent uploads the file / folder; poll
     * the operation, then follow its link.
     */
    public function download(Request $request, Volume $volume, DownloadFromVolume $download): JsonResponse
    {
        $this->authorize('browse', $volume);
        $this->hostPaths($request, $volume);
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'storage_provider_id' => ['required', 'string', 'size:26'],
        ]);

        $volume->loadMissing('attachments');
        $operation = $download($volume, (string) ($data['path'] ?? ''), $data['storage_provider_id'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentOperation($operation)], 202);
    }

    public function operation(Operation $operation): JsonResponse
    {
        $this->authorize('view', $operation);

        return response()->json(['data' => $this->presentOperation($operation)]);
    }

    /**
     * GET /volumes/operations/{operation}/file: a short-lived link to a finished download (redirect, or {url}).
     */
    public function file(Request $request, Operation $operation, DownloadFromVolume $download): RedirectResponse|JsonResponse
    {
        $this->authorize('browse', $operation);

        $url = $download->link($operation);

        return $request->wantsJson() && $request->header('X-Inertia') === null ? response()->json(['url' => $url]) : redirect()->away($url);
    }

    /** Host paths (bind volumes) are the server's own files: their browser is for admins. */
    private function hostPaths(Request $request, Volume $volume): void
    {
        if ($volume->kind === VolumeKind::Bind && ! $this->admin($request, $volume->organization_id)) {
            abort(403, 'Only admins can browse host paths.');
        }
    }

    private function admin(Request $request, string $organizationId): bool
    {
        return in_array($this->access->roleOf((string) $request->user()?->getAuthIdentifier(), $organizationId), [Role::Owner, Role::Admin], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function labels(array $data): array
    {
        $labels = [];

        foreach ((array) ($data['labels'] ?? []) as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,62}$/', $key) !== 1 || str_starts_with($key, 'falak.')) {
                throw ValidationException::withMessages(['labels' => 'Label keys use lowercase letters, digits, ".", "_" and "-" (and never start with "falak.").']);
            }

            $labels[$key] = (string) $value;
        }

        return $labels;
    }
}
