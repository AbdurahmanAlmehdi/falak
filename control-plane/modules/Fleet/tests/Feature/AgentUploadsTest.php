<?php

use Falak\Fleet\Contracts\AgentUploadHandler;
use Falak\Fleet\Contracts\AgentUploads;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/helpers.php';

final class EchoAgentUpload implements AgentUploadHandler
{
    public function receive(AgentCaller $caller, string $id, int $offset, string $chunk, array $headers): array
    {
        if ($chunk === 'refuse') {
            throw new AgentRequestRefused('nope', 'Refused.');
        }

        return ['server' => $caller->serverId, 'id' => $id, 'offset' => $offset, 'chunk' => $chunk, 'headers' => $headers];
    }
}

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'fleet.upload_chunk_bytes' => 16]);
    [, $this->organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($this->organization->id, $this->serverId);
    app(AgentUploads::class)->register('test.echo', EchoAgentUpload::class);
});

/**
 * @param  array<string, string>  $extra  server variables
 */
function fleet_upload(string $uri, string $body, array $extra = [], bool $mtls = true): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($body), 'HTTP_X_FALAK_CHUNK_SHA256' => hash('sha256', $body), 'HTTP_ACCEPT' => 'application/json', ...$extra];

    if ($mtls) {
        $server['HTTP_X_FALAK_CLIENT_CERT_FINGERPRINT'] = test()->enrolled['fingerprint'];
    }

    return test()->call('POST', $uri, [], [], [], $server, $body);
}

it('hands a checked chunk to the registered handler with the authenticated caller', function () {
    fleet_upload('/agent/v1/uploads/test.echo/abc_1?offset=8', 'hello', ['HTTP_X_FALAK_UPLOAD_SIZE' => '13', 'HTTP_X_OTHER' => 'no'])
        ->assertOk()
        ->assertExactJson(['server' => $this->serverId, 'id' => 'abc_1', 'offset' => 8, 'chunk' => 'hello', 'headers' => ['x-falak-upload-size' => '13']]);
});

it('refuses unknown types, strangers, oversized and corrupted chunks', function () {
    fleet_upload('/agent/v1/uploads/test.nobody/a?offset=0', 'x')->assertNotFound()->assertJson(['error' => 'unknown_upload']);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', 'x', mtls: false)->assertUnauthorized();
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', str_repeat('x', 17))->assertStatus(413);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', 'x', ['CONTENT_LENGTH' => '100'])->assertStatus(413)->assertJson(['error' => 'chunk_too_large']);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', str_repeat('x', 17), ['CONTENT_LENGTH' => '3'])->assertStatus(413);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', 'x', ['HTTP_X_FALAK_CHUNK_SHA256' => hash('sha256', 'y')])->assertStatus(422)->assertJson(['error' => 'chunk_checksum']);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=-1', 'x')->assertStatus(422)->assertJson(['error' => 'bad_offset']);
    fleet_upload('/agent/v1/uploads/test.echo/a?offset=0', 'refuse')->assertStatus(409)->assertJson(['error' => 'nope']);
    fleet_upload('/agent/v1/uploads/test.echo/..%2Fx?offset=0', 'x')->assertNotFound();
});
