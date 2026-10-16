<?php

namespace Falak\Fleet\Application;

use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Illuminate\Support\Carbon;

/**
 * State transitions of a command. Terminal transitions announce CommandFinished / CommandFailed exactly once
 * per transition, and broadcast the new status to live viewers.
 *
 * The events carry the result, except for types whose result is forgotten once read (x-falak-forget-result: sandbox
 * exec output, file contents): queued listeners serialize their event into the queue, so a 5 MB file read would be
 * copied into Redis once per listener and kept there (Horizon) after the control plane forgot it. Their listeners load
 * the command by id when they need more.
 */
final class CommandLifecycle
{
    public function __construct(private readonly ProtocolSchemas $schemas) {}

    public function markStarted(Command $command, ?Carbon $at = null): void
    {
        if (! in_array($command->status, [CommandStatus::Queued, CommandStatus::Delivered], true)) {
            return;
        }

        $command->forceFill([
            'status' => CommandStatus::Running,
            'started_at' => $at ?? now(),
            'delivered_at' => $command->delivered_at ?? now(),
        ])->save();
    }

    /**
     * Record the agent's `finished` event. A late result may override a control-plane-inferred timeout.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function finish(Command $command, ?int $exitCode, ?array $result, ?string $error, ?Carbon $at = null): void
    {
        if ($command->status->isTerminal() && $command->status !== CommandStatus::TimedOut) {
            return;
        }

        $succeeded = ($exitCode ?? 0) === 0 && ($error === null || $error === '');

        $command->forceFill([
            'status' => $succeeded ? CommandStatus::Succeeded : CommandStatus::Failed,
            'exit_code' => $exitCode,
            'result' => $result,
            'error' => $error,
            'started_at' => $command->started_at ?? $at ?? now(),
            'finished_at' => $at ?? now(),
        ])->save();

        $this->announce($command);
    }

    /**
     * Control-plane-side failure (timeout, cancellation, undeliverable, agent revoked).
     */
    public function fail(Command $command, CommandStatus $status, string $error): void
    {
        if ($command->status->isTerminal()) {
            return;
        }

        $command->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        $this->announce($command);
    }

    private function announce(Command $command): void
    {
        CommandOutputReceived::dispatch($command->id, $command->server_id, $command->status->value, []);
        $result = in_array($command->type, $this->schemas->forgettableResults(), true) ? null : $command->result;

        if ($command->status === CommandStatus::Succeeded) {
            CommandFinished::dispatch(
                $command->id,
                $command->organization_id,
                $command->server_id,
                $command->type,
                $command->idempotency_key,
                $command->exit_code,
                $result,
            );

            return;
        }

        CommandFailed::dispatch(
            $command->id,
            $command->organization_id,
            $command->server_id,
            $command->type,
            $command->idempotency_key,
            $command->status->value,
            $command->error,
            $command->exit_code,
            $result,
        );
    }
}
