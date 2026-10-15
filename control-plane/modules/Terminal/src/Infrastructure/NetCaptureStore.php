<?php

namespace Falak\Terminal\Infrastructure;

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\Sealer;
use Falak\Terminal\Domain\Models\NetCapture;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Packet capture files at rest (docs/plans/network/capture.md): every capture has its own random data key, sealed under
 * the organization's key; every uploaded chunk is sealed with it (AES-256-GCM, the AAD naming the capture and the
 * offset, so chunks can't be moved or reordered) and stored whole as net-captures/<capture>/<offset>.bin on the
 * terminal.captures.disk. Nothing is ever appended, so any Flysystem disk works.
 */
final class NetCaptureStore
{
    public function __construct(private readonly Sealer $sealer) {}

    /** A new sealed data key for a capture (kept on its row). */
    public function newKey(string $organizationId, string $captureId): string
    {
        $key = random_bytes(Aead::KEY_BYTES);

        try {
            return $this->sealer->seal($key, self::keyAad($organizationId, $captureId), $organizationId);
        } finally {
            sodium_memzero($key);
        }
    }

    public function put(NetCapture $capture, int $offset, string $chunk): void
    {
        $key = $this->key($capture);

        try {
            $this->disk()->put(self::path($capture->id, $offset), Aead::seal($key, $chunk, self::chunkAad($capture->id, $offset)));
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * Decrypt the chunks in order, handing each to $write (the download stream, the checksum).
     *
     * @param  callable(string): void  $write
     */
    public function each(NetCapture $capture, callable $write): void
    {
        $key = $this->key($capture);

        try {
            foreach ($this->offsets($capture->id) as $offset) {
                $write(Aead::open($key, (string) $this->disk()->get(self::path($capture->id, $offset)), self::chunkAad($capture->id, $offset)));
            }
        } finally {
            sodium_memzero($key);
        }
    }

    /** SHA-256 of the decrypted file. */
    public function sha256(NetCapture $capture): string
    {
        $hash = hash_init('sha256');
        $this->each($capture, function (string $chunk) use ($hash) {
            hash_update($hash, $chunk);
        });

        return hash_final($hash);
    }

    public function delete(string $captureId): void
    {
        $this->disk()->deleteDirectory("net-captures/{$captureId}");
    }

    /** @return list<int> */
    public function offsets(string $captureId): array
    {
        $offsets = [];

        foreach ($this->disk()->files("net-captures/{$captureId}") as $file) {
            if (preg_match('/\/(\d{12})\.bin$/', $file, $m) === 1) {
                $offsets[] = (int) $m[1];
            }
        }

        sort($offsets);

        return $offsets;
    }

    public static function path(string $captureId, int $offset): string
    {
        return sprintf('net-captures/%s/%012d.bin', $captureId, $offset);
    }

    private function key(NetCapture $capture): string
    {
        return $this->sealer->open($capture->data_key, self::keyAad($capture->organization_id, $capture->id), $capture->organization_id);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('terminal.captures.disk', 'local'));
    }

    private static function keyAad(string $organizationId, string $captureId): string
    {
        return Sealer::aad('net-capture', $organizationId, $captureId);
    }

    private static function chunkAad(string $captureId, int $offset): string
    {
        return "net-capture:{$captureId}:{$offset}";
    }
}
