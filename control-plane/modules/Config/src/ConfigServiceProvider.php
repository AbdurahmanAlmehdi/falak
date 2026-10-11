<?php

namespace Falak\Config;

use Falak\Kernel\Support\ModuleServiceProvider;

/**
 * Config as code (`falak.yaml`, docs/plans/CONFIG_AS_CODE.md): the schema (contracts/config/v1.schema.json), the
 * planner and the run history. Other modules take part by implementing {@see Contracts\Reconcilable} for the sections
 * of the file they own.
 *
 * Phase 0: the contracts only. Permissions (config.view, config.apply, config.approve), tables, routes and the
 * reconciler registry arrive with phase 1.
 */
class ConfigServiceProvider extends ModuleServiceProvider
{
    /** Path of the v1 JSON Schema, relative to the repository root. */
    public const SCHEMA = 'contracts/config/v1.schema.json';

    /** The schema's $id. */
    public const SCHEMA_ID = 'https://falak.sh/schema/v1.json';
}
