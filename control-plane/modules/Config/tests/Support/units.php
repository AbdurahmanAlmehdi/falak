<?php

use Falak\Fleet\Domain\Models\Agent;

require_once __DIR__.'/helpers.php';

/*
| Phase 3 units: a falak.yaml with an app unit mounting a function unit whose code is in the repository.
*/

const CONFIG_FUNCTION_FILE = <<<'YAML'
version: 1
units:
  web:
    type: app
    root: app
    runtime: frankenphp
    php: "8.4"
    mounts:
      - { path: /api, function: api }
  api:
    type: function
    root: fn
    runtime: bun
    scaling: { min_instances: 1, max_instances: 3 }
    memory: 512m
    timeout: 1m
    access: { allow: [10.0.0.0/8] }
    env: { GREETING: hello }
    schedules:
      nightly: { cron: "0 3 * * *", timezone: Europe/Berlin }
environments:
  production: { branch: main, servers: [web-1] }

YAML;

const CONFIG_FN_V1 = "import { Hono } from 'hono'\nimport { db } from './lib/db'\nconst app = new Hono()\napp.get('/', (c) => c.text('v1'))\nexport default app\n";

function config_function_world(): ConfigWorld
{
    $world = config_world();
    Agent::factory()->create(['server_id' => $world->deploy->servers[0]->id, 'organization_id' => $world->deploy->organization->id,
        'facts' => ['features' => ['fn.v1', 'fn.v2', 'fn.v3'], 'memory_bytes' => 4 * 1024 ** 3]]);
    $world->file(CONFIG_FUNCTION_FILE);
    $world->deploy->sourceControl->files += [
        'fn/index.ts' => CONFIG_FN_V1,
        'fn/lib/db.ts' => "export const db = 'postgres'\n",
        'fn/.env' => "SECRET=1\n",
        'fn/node_modules/hono/index.js' => "module.exports = {}\n",
        'README.md' => "# shop\n",
    ];

    return $world;
}
