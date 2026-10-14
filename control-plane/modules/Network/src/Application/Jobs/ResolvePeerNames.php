<?php

namespace Falak\Network\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Reverse DNS of external peers (docs/plans/network/connections.md): cached in network_peer_names for a day; a failed
 * lookup is cached too (name null), so an address is asked at most once a day. Never on the agent, never per sample.
 */
final class ResolvePeerNames implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    /** @var (callable(string): ?string)|null replaced in tests */
    public static $resolver = null;

    /**
     * @param  list<string>  $addresses
     */
    public function __construct(public array $addresses) {}

    public function handle(): void
    {
        $resolve = self::$resolver ?? static function (string $address): ?string {
            $name = @gethostbyaddr($address);

            return is_string($name) && $name !== $address ? $name : null;
        };

        foreach (array_slice($this->addresses, 0, 50) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $name = $resolve($address);
            DB::table('network_peer_names')->upsert(
                [['address' => $address, 'name' => $name !== null ? mb_substr($name, 0, 255) : null, 'resolved_at' => now()]],
                ['address'],
                ['name', 'resolved_at'],
            );
        }
    }
}
