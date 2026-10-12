<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // allowed_servers held server names in the release candidates; it holds server ids now (names that match no
        // server are dropped).
        foreach (DB::table('config_sources')->whereNotNull('allowed_servers')->get(['id', 'organization_id', 'allowed_servers']) as $source) {
            $names = (array) json_decode((string) $source->allowed_servers, true);
            $ids = DB::table('servers_servers')->where('organization_id', $source->organization_id)
                ->where(fn ($q) => $q->whereIn('name', $names)->orWhereIn('id', $names))->pluck('id')->all();
            DB::table('config_sources')->where('id', $source->id)->update(['allowed_servers' => json_encode(array_values($ids))]);
        }
    }

    public function down(): void {}
};
