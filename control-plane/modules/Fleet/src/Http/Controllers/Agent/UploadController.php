<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Fleet\Contracts\AgentUploadHandler;
use Falak\Fleet\Contracts\AgentUploads;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /agent/v1/uploads/{type}/{id}?offset=N — one raw chunk of an agent upload, handed to the module that registered
 * the type (AgentUploads). 404 `unknown_upload`, 411 without Content-Length, 413 above fleet.upload_chunk_bytes, 422
 * when X-Falak-Chunk-Sha256 is missing or doesn't match, 409 with a reason code when the handler refuses.
 */
final class UploadController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, string $type, string $id, AgentUploads $uploads): JsonResponse
    {
        $class = $uploads->handler($type);
        $agent = $this->agent($request);

        if ($class === null) {
            return response()->json(['message' => "Unknown agent upload [{$type}].", 'error' => 'unknown_upload'], 404);
        }

        if ($agent->server_id === null) {
            return response()->json(['message' => 'This agent has no server.', 'error' => 'no_server'], 409);
        }

        $max = (int) config('fleet.upload_chunk_bytes', 8 * 1024 * 1024);
        $length = $request->headers->get('Content-Length');

        if ($length === null || ! ctype_digit($length)) {
            return response()->json(['message' => 'Content-Length is required.', 'error' => 'length_required'], 411);
        }

        if ((int) $length > $max) {
            return response()->json(['message' => "Chunks are at most {$max} bytes.", 'error' => 'chunk_too_large'], 413);
        }

        $offset = $request->query('offset');

        if (! is_string($offset) || ! ctype_digit($offset) || strlen($offset) > 12) {
            return response()->json(['message' => 'offset must be a non-negative integer.', 'error' => 'bad_offset'], 422);
        }

        $chunk = $this->body($request, $max);

        if ($chunk === null) {
            return response()->json(['message' => "Chunks are at most {$max} bytes.", 'error' => 'chunk_too_large'], 413);
        }

        $sum = strtolower((string) $request->headers->get('X-Falak-Chunk-Sha256', ''));

        if (! hash_equals(hash('sha256', $chunk), $sum)) {
            return response()->json(['message' => 'The chunk does not match X-Falak-Chunk-Sha256.', 'error' => 'chunk_checksum'], 422);
        }

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (str_starts_with($name, 'x-falak-upload-')) {
                $headers[$name] = (string) ($values[0] ?? '');
            }
        }

        /** @var AgentUploadHandler $handler */
        $handler = app($class);

        try {
            return response()->json($handler->receive(new AgentCaller($agent->id, $agent->organization_id, (string) $agent->server_id), $id, (int) $offset, $chunk, $headers));
        } catch (AgentRequestRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'error' => $refused->reason], 409);
        }
    }

    /** The body, read with a limit (null past $max: Content-Length can't be trusted to bound it). */
    private function body(Request $request, int $max): ?string
    {
        $in = $request->getContent(true);

        if (! is_resource($in)) {
            $body = (string) $in;

            return strlen($body) > $max ? null : $body;
        }

        $body = stream_get_contents($in, $max + 1);

        return $body === false || strlen($body) > $max ? null : $body;
    }
}
