<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Application\Actions\QueueCommand;
use Falak\Fleet\Application\CommandLifecycle;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Data\CommandOutput;
use Falak\Fleet\Contracts\Data\CommandResult;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Domain\Models\CommandEvent;
use Illuminate\Support\Arr;

final class FleetAgentGateway implements AgentGateway
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly QueueCommand $queue,
        private readonly CommandLifecycle $lifecycle,
        private readonly ProtocolSchemas $schemas,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? fn (int $microseconds) => usleep($microseconds);
    }

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        return ($this->queue)($serverId, $type, $payload, $timeout, $idempotencyKey)->toHandle();
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        $id = $this->id($command);
        $deadline = microtime(true) + $waitSeconds;

        while (true) {
            $result = $this->status($id);

            if ($result->isFinished()) {
                if (in_array($result->type, $this->schemas->forgettableResults(), true)) {
                    self::forgetResults([$id]);
                }

                return $result;
            }

            if (microtime(true) >= $deadline) {
                throw CommandTimedOut::waiting($id, $waitSeconds);
            }

            ($this->sleeper)(500_000);
        }
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        return Command::query()->findOrFail($this->id($command))->toResult();
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        $id = $this->id($command);

        $lines = CommandEvent::query()
            ->where('command_id', $id)
            ->where('kind', 'output')
            ->where('seq', '>', $afterSeq)
            ->orderBy('seq')
            ->get(['seq', 'stream', 'data', 'at'])
            ->map(fn (CommandEvent $event) => [
                'seq' => $event->seq,
                'stream' => $event->stream ?? 'stdout',
                'data' => (string) $event->data,
                'at' => $event->at->toIso8601String(),
            ])
            ->values()
            ->all();

        $last = $lines === [] ? $afterSeq : $lines[array_key_last($lines)]['seq'];

        return new CommandOutput($id, $lines, $last);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        $model = Command::query()->findOrFail($this->id($command));

        if ($model->status !== CommandStatus::Queued) {
            return $model->status === CommandStatus::Cancelled;
        }

        $this->lifecycle->fail($model, CommandStatus::Cancelled, 'Cancelled before delivery.');

        return true;
    }

    public function supports(string $type): bool
    {
        return $this->schemas->hasCommand($type);
    }

    public function forgetSecrets(CommandHandle|string $command, array $paths): bool
    {
        $model = Command::query()->find($this->id($command));

        if ($model === null || ! $model->status->isTerminal()) {
            return false;
        }

        self::forget($model, $paths);

        return true;
    }

    /**
     * Replace each dotted path present in the stored payload with "[forgotten]" and mark the command done with it
     * (also used by the fleet sweep for commands whose listener missed it, or stuck past their timeout).
     *
     * @param  list<string>  $paths
     */
    public static function forget(Command $model, array $paths): void
    {
        $payload = json_decode($model->payload, true, 512, JSON_THROW_ON_ERROR);
        $changed = false;

        if (is_array($payload)) {
            foreach (array_merge(...array_map(fn (string $path) => self::expand($payload, $path), $paths)) as $path) {
                if (Arr::has($payload, $path) && Arr::get($payload, $path) !== '[forgotten]') {
                    Arr::set($payload, $path, '[forgotten]');
                    $changed = true;
                }
            }
        }

        $model->forceFill(array_filter([
            'payload' => $changed ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : null,
            'secrets_forgotten_at' => now(),
        ]))->save();
    }

    /**
     * Drop the results of commands (and of their events) that are only for the request that waited on them
     * (ProtocolSchemas::FORGET_RESULT); also used by the fleet sweep for those nobody awaited.
     *
     * @param  list<string>  $ids
     */
    public static function forgetResults(array $ids): void
    {
        Command::query()->whereKey($ids)->whereNotNull('result')->update(['result' => null]);
        CommandEvent::query()->whereIn('command_id', $ids)->whereNotNull('result')->update(['result' => null]);
    }

    /**
     * A dotted path with `*` segments (segments.*.encryption.key) as the concrete paths present in $payload.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private static function expand(array $payload, string $path): array
    {
        if (! str_contains($path, '*')) {
            return [$path];
        }

        [$head, $tail] = explode('*', $path, 2);
        $head = rtrim($head, '.');
        $list = $head === '' ? $payload : Arr::get($payload, $head);

        if (! is_array($list)) {
            return [];
        }

        $out = [];

        foreach (array_keys($list) as $key) {
            array_push($out, ...self::expand($payload, ltrim(($head === '' ? '' : "{$head}.").$key.$tail, '.')));
        }

        return $out;
    }

    private function id(CommandHandle|string $command): string
    {
        return $command instanceof CommandHandle ? $command->id : $command;
    }
}
