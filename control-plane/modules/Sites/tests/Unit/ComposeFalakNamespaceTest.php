<?php

use Falak\Sites\Infrastructure\Compose\YamlComposeInspector;

/*
 * A compose file never reaches into Falak's own containers, networks or labels: database containers sit on the
 * environment networks (falak-env-*), their secrets and spec hashes on falak.* labels.
 */

function compose_errors(string $yaml): array
{
    return (new YamlComposeInspector)->parse($yaml)->errors;
}

it('refuses Falak networks, whatever they are called in the file', function (string $networks) {
    $errors = compose_errors("services:\n  app:\n    image: nginx\n    networks: [db]\nnetworks:\n{$networks}");

    expect(implode(' ', $errors))->toContain("Falak's networks are not available to compose files");
})->with([
    'external by key' => "  falak-env-01hzyenv000000000000000001:\n    external: true\n",
    'external by name' => "  db:\n    external: true\n    name: falak-env-01hzyenv000000000000000001\n",
    'legacy external name' => "  db:\n    external:\n      name: falak-edge\n",
    'own network named like one' => "  db:\n    name: falak-env-01hzyenv000000000000000001\n",
]);

it('refuses namespaces of containers outside the stack, and service: names that are not in it', function () {
    expect(implode(' ', compose_errors("services:\n  app:\n    image: nginx\n    network_mode: container:falak-db-01hzyinst00000000000000001\n")))->toContain("shares another container's namespace")
        ->and(implode(' ', compose_errors("services:\n  app:\n    image: nginx\n    pid: container:abc\n")))->toContain("shares another container's namespace")
        ->and(implode(' ', compose_errors("services:\n  app:\n    image: nginx\n    network_mode: service:falak-db\n")))->toContain('names no service of this file')
        ->and(compose_errors("services:\n  app:\n    image: nginx\n  sidecar:\n    image: busybox\n    network_mode: service:app\n"))->toBe([]);
});

it('refuses falak.* labels set by the file, except the leader command and the environment network opt-out', function () {
    expect(implode(' ', compose_errors("services:\n  app:\n    image: nginx\n    labels:\n      falak.db.instance: 01hzyinst00000000000000001\n")))->toContain("sets Falak's labels (falak.db.instance)")
        ->and(implode(' ', compose_errors("services:\n  app:\n    image: nginx\n    labels: [\"falak.managed=true\"]\n")))->toContain('falak.managed')
        ->and(implode(' ', compose_errors("services:\n  app:\n    image: nginx\nvolumes:\n  data:\n    labels: {falak.volume.id: x}\n")))->toContain("Volume data sets Falak's labels")
        ->and(compose_errors("services:\n  app:\n    image: nginx\n    labels:\n      falak.deploy.leader_command: php artisan migrate --force\n      com.example.team: web\n"))->toBe([])
        ->and(compose_errors("services:\n  sandbox:\n    image: nginx\n    labels:\n      falak.environment_network: none\n"))->toBe([]);
});

it('refuses the control plane\'s volumes and volumes_from Falak containers', function (string $yaml, string $error) {
    expect(implode(' ', compose_errors($yaml)))->toContain($error);
})->with([
    'external by key' => ["services:\n  app:\n    image: nginx\n    volumes: [falak_pg-data:/data]\nvolumes:\n  falak_pg-data:\n    external: true\n", "Falak's own volumes are not available"],
    'external by name' => ["services:\n  app:\n    image: nginx\n    volumes: [db:/data]\nvolumes:\n  db:\n    external: true\n    name: falak_pg-data\n", 'Volume db is falak_pg-data'],
    'legacy external name' => ["services:\n  app:\n    image: nginx\n    volumes: [ca:/ca]\nvolumes:\n  ca:\n    external:\n      name: falak_falak-ca\n", 'Volume ca is falak_falak-ca'],
    'named like one' => ["services:\n  app:\n    image: nginx\n    volumes: [v:/v]\nvolumes:\n  v:\n    name: falak-vol-1\n", 'Volume v is falak-vol-1'],
    'volumes_from' => ["services:\n  app:\n    image: nginx\n    volumes_from: ['container:falak-postgres-1:ro']\n", "mounts a Falak container's volumes"],
]);

it('keeps volumes that only look alike', function () {
    expect(compose_errors("services:\n  app:\n    image: nginx\n    volumes: [falak:/data, falakdata:/x]\n    volumes_from: [db]\n  db:\n    image: postgres\nvolumes:\n  falak: {}\n  falakdata:\n    name: falakdata\n"))->toBe([]);
});
