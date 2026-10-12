<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\CreateDatabase;
use Falak\Databases\Application\Actions\DeleteDatabase;
use Falak\Databases\Application\Actions\RunBackup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DatabaseController extends Controller
{
    use GuardsConfigManaged;

    public function store(Request $request, DatabaseInstance $instance, CreateDatabase $create): RedirectResponse
    {
        $this->authorize('manage', $instance);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'charset' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'collation' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'site_id' => ['nullable', 'ulid'],
            'user' => ['nullable', 'array'],
            'user.username' => ['nullable', 'string', 'max:63'],
            'user.password' => ['nullable', 'string', 'min:12', 'max:128'],
            'user.host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.%_:-]+$/'],
        ]);

        $create($instance, $data, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(Request $request, Database $database, DeleteDatabase $delete): RedirectResponse
    {
        $this->authorize('manage', $database);

        $request->validate(['confirm' => ['required', 'string', 'in:'.$database->name]], ['confirm.in' => 'Type the database name to confirm.']);
        $this->guardManaged('database', $database->id, 'confirm', 'this database');

        // The first database of a managed container is its unit's canvas service: deleting it would unlink the unit.
        $first = $database->instance->databases()->reorder()->orderBy('created_at')->orderBy('id')->value('id');

        if ($first === $database->id) {
            $this->guardManaged('database_instance', $database->database_instance_id, 'confirm', "this database server's first database (its unit's service)");
        }

        $delete($database);

        return back();
    }

    public function backup(Request $request, Database $database, RunBackup $run): RedirectResponse
    {
        $this->authorize('manage', $database);

        $data = $request->validate([
            'storage_provider_id' => ['required', 'string'],
        ]);

        $provider = StorageProvider::query()->where('organization_id', $database->organization_id)->findOrFail($data['storage_provider_id']);

        $run($database, $provider, 'manual', null, $request->user()?->getAuthIdentifier());

        return back();
    }
}
