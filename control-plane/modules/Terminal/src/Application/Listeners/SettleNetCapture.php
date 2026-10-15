<?php

namespace Falak\Terminal\Application\Listeners;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Terminal\Domain\Models\NetCapture;
use Falak\Terminal\Infrastructure\NetCaptureStore;

/**
 * Settles a packet capture when its net.capture command ends: a finished command must have uploaded the whole file (the
 * upload made it ready); anything else fails or cancels the capture and deletes what was uploaded.
 */
final class SettleNetCapture
{
    public function __construct(private readonly NetCaptureStore $store) {}

    public function handleFinished(CommandFinished $event): void
    {
        $capture = $this->capture($event->type, $event->commandId);

        if ($capture === null) {
            return;
        }

        $result = $event->result ?? [];

        if ($capture->status === NetCapture::READY) {
            $capture->forceFill([
                'result' => $result,
                'packets_captured' => (int) ($result['packets'] ?? $capture->packets_captured),
                'finished_at' => now(),
            ])->save();

            return;
        }

        if ($capture->isActive()) {
            $this->end($capture, NetCapture::FAILED, 'The capture ended without a complete upload.', $result);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        $capture = $this->capture($event->type, $event->commandId);

        if ($capture === null || ! $capture->isActive()) {
            return;
        }

        // A capture the user cancelled is cancelled however the agent ended it (e.g. its upload refused after the cancel).
        $cancelled = $event->status === 'cancelled' || $event->exitCode === 130 || $capture->cancel_requested_at !== null;
        $this->end($capture, $cancelled ? NetCapture::CANCELLED : NetCapture::FAILED, $cancelled ? null : ($event->error ?? 'The capture failed.'), $event->result);
    }

    /**
     * @param  ?array<string, mixed>  $result
     */
    private function end(NetCapture $capture, string $status, ?string $error, ?array $result): void
    {
        $this->store->delete($capture->id);
        $capture->forceFill([
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 255) : null,
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    private function capture(string $type, string $commandId): ?NetCapture
    {
        return $type === 'net.capture' ? NetCapture::query()->where('command_id', $commandId)->first() : null;
    }
}
