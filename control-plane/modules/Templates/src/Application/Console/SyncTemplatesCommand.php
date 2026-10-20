<?php

namespace Falak\Templates\Application\Console;

use Falak\Templates\Application\Catalog\CatalogSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetch the published template catalog (scheduled hourly). A failure keeps the catalog the panel serves now.
 */
final class SyncTemplatesCommand extends Command
{
    protected $signature = 'templates:sync';

    protected $description = 'Download the latest published template catalog (FALAK_TEMPLATES_URL)';

    public function handle(CatalogSync $sync): int
    {
        try {
            $this->line($sync->sync());
        } catch (RuntimeException $e) {
            Log::warning("Template catalog sync failed: {$e->getMessage()}");
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
