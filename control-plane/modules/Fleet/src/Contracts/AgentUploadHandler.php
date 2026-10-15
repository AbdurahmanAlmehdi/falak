<?php

namespace Falak\Fleet\Contracts;

use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;

/**
 * Takes one chunk of an agent upload ({@see AgentUploads}). The caller is the authenticated agent: a handler only ever
 * accepts uploads for what belongs to its server.
 */
interface AgentUploadHandler
{
    /**
     * @param  string  $id  the upload's subject (the URL's last segment, at most 64 characters of [A-Za-z0-9_-])
     * @param  int  $offset  where the chunk starts (the URL's ?offset=)
     * @param  string  $chunk  the body, its SHA-256 already checked against X-Falak-Chunk-Sha256
     * @param  array<string, string>  $headers  the request's X-Falak-Upload-* headers (lower-case names)
     * @return array<string, mixed> the JSON reply
     *
     * @throws AgentRequestRefused (409 with a reason code)
     */
    public function receive(AgentCaller $caller, string $id, int $offset, string $chunk, array $headers): array;
}
