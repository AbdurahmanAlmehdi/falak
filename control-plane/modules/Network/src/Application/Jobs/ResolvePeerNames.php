<?php

namespace Falak\Network\Application\Jobs;

use Falak\Network\Infrastructure\PtrResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Reverse DNS of external peers (docs/plans/network/connections.md): one PTR query per address with a 1 s timeout
 * (PtrResolver), cached in network_peer_names for a day. The addresses already have a placeholder row (name null) when
 * this is queued, so the next reports do not queue them again; one job per server at a time.
 */
final class ResolvePeerNames implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var (callable(string): ?string)|null replaced in tests */
    public static $resolver = null;

    /**
     * @param  list<string>  $addresses
     */
    public function __construct(public string $serverId, public array $addresses) {}

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(): void
    {
        $resolver = new PtrResolver;
        $resolve = self::$resolver ?? fn (string $address): ?string => $resolver->resolve($address);

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
