<?php

namespace Falak\Fleet\Contracts;

/**
 * Raw uploads from agents (POST /agent/v1/uploads/<type>/<id>?offset=N, application/octet-stream): Fleet authenticates
 * the agent, bounds the chunk (fleet.upload_chunk_bytes), checks its X-Falak-Chunk-Sha256 and hands it to the module
 * that registered the type. Example: Terminal takes packet captures (net.capture).
 */
interface AgentUploads
{
    /**
     * @param  string  $type  dotted, e.g. "net.capture"
     * @param  class-string<AgentUploadHandler>  $handler  resolved from the container per request
     */
    public function register(string $type, string $handler): void;

    /** @return ?class-string<AgentUploadHandler> */
    public function handler(string $type): ?string;
}
