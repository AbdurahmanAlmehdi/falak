<?php

namespace Falak\Terminal\Infrastructure\AgentRequests;

use Falak\Fleet\Contracts\AgentUploadHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Falak\Terminal\Domain\Models\NetCapture;
use Falak\Terminal\Infrastructure\NetCaptureStore;
use Illuminate\Support\Facades\DB;

/**
 * Chunks of a packet capture's pcap (POST /agent/v1/uploads/net.capture/<capture>?offset=N, docs/plans/network/capture.md).
 *
 * Only the agent of the capture's server can upload, only while the capture is in progress, at the next offset, within
 * the declared size (at most max_bytes); the declared size and SHA-256 can't change between chunks. A chunk that already
 * landed (a retry after a lost reply) is answered again without being stored twice. The last chunk is checked against
 * the whole file's SHA-256 before the capture is ready.
 */
final class NetCaptureUpload implements AgentUploadHandler
{
    private const PCAP_HEADER = 24;

    public function __construct(private readonly NetCaptureStore $store) {}

    public function receive(AgentCaller $caller, string $id, int $offset, string $chunk, array $headers): array
    {
        $size = $headers['x-falak-upload-size'] ?? '';
        $sha = strtolower($headers['x-falak-upload-sha256'] ?? '');

        if (! ctype_digit($size) || strlen($size) > 12 || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1 || $chunk === '') {
            throw new AgentRequestRefused('bad_upload', 'X-Falak-Upload-Size and X-Falak-Upload-Sha256 are required, and the chunk must not be empty.');
        }

        $size = (int) $size;
        $chunkSha = hash('sha256', $chunk);

        $reply = DB::transaction(function () use ($caller, $id, $offset, $chunk, $size, $sha, $chunkSha) {
            $capture = NetCapture::query()
                ->where('organization_id', $caller->organizationId)
                ->where('server_id', $caller->serverId)
                ->lockForUpdate()
                ->find(strtolower($id));

            if ($capture === null) {
                throw new AgentRequestRefused('unknown_capture', 'No such capture on this server.');
            }

            $retry = $offset + strlen($chunk) === $capture->bytes_received && $capture->last_chunk_sha256 === $chunkSha;

            if ($retry && in_array($capture->status, [...NetCapture::ACTIVE, NetCapture::READY], true)) {
                return ['received' => $capture->bytes_received, 'status' => $capture->status];
            }

            if (! $capture->isActive() || $capture->cancel_requested_at !== null) {
                throw new AgentRequestRefused('not_accepting', "The capture is {$capture->status}; it takes no more data.");
            }

            if ($size < self::PCAP_HEADER || $size > $capture->max_bytes) {
                throw new AgentRequestRefused('too_large', "The capture is limited to {$capture->max_bytes} bytes.");
            }

            if (($capture->size_bytes !== null && $capture->size_bytes !== $size) || ($capture->sha256 !== null && $capture->sha256 !== $sha)) {
                throw new AgentRequestRefused('upload_changed', 'The declared size or checksum changed during the upload.');
            }

            if ($offset !== $capture->bytes_received) {
                throw new AgentRequestRefused('offset_mismatch', "Expected offset {$capture->bytes_received}.");
            }

            $received = $offset + strlen($chunk);

            if ($received > $size) {
                throw new AgentRequestRefused('too_large', 'The chunk goes past the declared size.');
            }

            $this->store->put($capture, $offset, $chunk);
            $capture->forceFill([
                'status' => NetCapture::UPLOADING,
                'started_at' => $capture->started_at ?? now(),
                'bytes_received' => $received,
                'last_chunk_sha256' => $chunkSha,
                'size_bytes' => $size,
                'sha256' => $sha,
            ])->save();

            if ($received === $size) {
                if (! hash_equals($sha, $this->store->sha256($capture))) {
                    $this->store->delete($capture->id);
                    $capture->forceFill(['status' => NetCapture::FAILED, 'error' => 'The uploaded file does not match its checksum.', 'finished_at' => now()])->save();

                    return null; // refused below, once the failure is committed
                }

                $capture->forceFill(['status' => NetCapture::READY, 'bytes_captured' => $size])->save();
            }

            return ['received' => $received, 'status' => $capture->status];
        });

        if ($reply === null) {
            throw new AgentRequestRefused('checksum_mismatch', 'The uploaded file does not match X-Falak-Upload-Sha256.');
        }

        return $reply;
    }
}
