<?php

namespace Falak\Config\Contracts;

/**
 * A {@see Reconcilable} that checks its sections against the organization (stage 4 of validation): bounds the schema
 * can't express, values only valid for some runtimes, names of resources it owns. Issues use the codes of the phase 0
 * contract (`value.range`, `value.unsupported`, `value.conflict`, `limit.server`, `name.*`) with pointers into the
 * resolved file; Config adds the line and column.
 */
interface ValidatesConfig
{
    /**
     * @param  array<string, mixed>  $resolved  the whole resolved file (not pruned: e.g. a unit's runtime decides its strategies)
     * @return list<ConfigIssue>
     */
    public function validate(ConfigScope $scope, array $resolved): array;
}
