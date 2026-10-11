<?php

namespace Falak\Functions\Application;

use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionApiKey;
use Falak\Functions\Domain\Models\FunctionDraft;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Functions\Domain\Models\FunctionVersionCommit;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Support\Facades\DB;

/**
 * Functions and their versions. A function row exists for every function site; sites created elsewhere (API,
 * environment copies) get one, with the Hello starter, the first time it is needed.
 */
final class FunctionStore
{
    public function find(string $siteId): ?CloudFunction
    {
        return CloudFunction::query()->where('site_id', strtolower($siteId))->first();
    }

    public function ensure(SiteData $site, string $starter = 'hello', ?string $userId = null, ?string $userName = null, ?string $runtime = null): CloudFunction
    {
        $existing = $this->find($site->id);

        if ($existing !== null) {
            return $existing;
        }

        $runtime ??= (string) config('functions.default_runtime', 'bun');
        $entrypoint = (string) config("functions.runtimes.{$runtime}.entrypoint", 'index.ts');
        $family = (string) config("functions.runtimes.{$runtime}.family", 'ts');

        return DB::transaction(function () use ($site, $runtime, $entrypoint, $family, $starter, $userId, $userName) {
            $function = CloudFunction::query()->firstOrCreate(['site_id' => $site->id], [
                'organization_id' => $site->organizationId,
                'runtime' => $runtime,
                'entrypoint' => $entrypoint,
                ...array_intersect_key((array) config('functions.defaults'), array_flip(['min_instances', 'max_instances', 'concurrency', 'idle_timeout_s', 'memory_mb', 'cpus', 'request_timeout_s'])),
            ]);

            if ($function->wasRecentlyCreated) {
                $this->addVersion($function, [$entrypoint => Starters::content($starter, $family)], 'Created from the '.Starters::ALL[$starter]['title'].' starter', $userId, $userName, null);
            }

            return $function;
        });
    }

    /**
     * @param  array<string, string>  $files  validated (Code::files)
     */
    public function addVersion(CloudFunction $function, array $files, ?string $message, ?string $userId, ?string $userName, ?string $baseVersionId): FunctionVersion
    {
        return DB::transaction(function () use ($function, $files, $message, $userId, $userName, $baseVersionId) {
            CloudFunction::query()->whereKey($function->id)->lockForUpdate()->first();
            $number = (int) FunctionVersion::query()->where('function_id', $function->id)->max('number') + 1;

            return FunctionVersion::query()->create([
                'function_id' => $function->id,
                'number' => $number,
                'files' => $files,
                'entrypoint' => $function->entrypoint,
                'hash' => Code::hash($files, $function->entrypoint),
                'size' => Code::size($files),
                'message' => $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 500) : null,
                'author_id' => $userId,
                'author_name' => $userName,
                'base_version_id' => $baseVersionId,
                'created_at' => now(),
            ]);
        });
    }

    public function forget(string $siteId): void
    {
        $function = $this->find($siteId);

        if ($function === null) {
            return;
        }

        DB::transaction(function () use ($function) {
            FunctionDraft::query()->where('function_id', $function->id)->delete();
            FunctionSchedule::query()->where('function_id', $function->id)->delete();
            FunctionApiKey::query()->where('function_id', $function->id)->delete();
            FunctionVersionCommit::query()->where('function_id', $function->id)->delete();
            FunctionVersion::query()->where('function_id', $function->id)->delete();
            $function->delete();
        });
    }
}
