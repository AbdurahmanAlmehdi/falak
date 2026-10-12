<?php

namespace Falak\Sites\Contracts;

use InvalidArgumentException;

/**
 * A deploy script's sections around its macro lines, the shape of falak.yaml's `deploy.hooks`:
 *
 *     <before_fetch>  $FALAK_FETCH  <before_activate>  $FALAK_ACTIVATE  <after_activate>  $FALAK_RESTART_PROCS  <after_restart>
 *
 * Splits exactly like Deployments' ScriptSections::parse (a missing $FALAK_FETCH puts the head before activation, a
 * missing $FALAK_ACTIVATE puts everything before it), but keeps each section's text as written (comments included),
 * so a script can be put back together with one section replaced.
 */
final class DeployScriptSections
{
    public const SECTIONS = ['before_fetch', 'before_activate', 'after_activate', 'after_restart'];

    private const MACRO_LINE = '/^\s*\$\{?(FALAK_FETCH|FALAK_ACTIVATE|FALAK_RESTART_PROCS)\}?\s*(;\s*)?(#.*)?$/';

    /**
     * @return array{before_fetch: string, before_activate: string, after_activate: string, after_restart: string} each trimmed, '' when empty
     *
     * @throws InvalidArgumentException when macros repeat or are out of order
     */
    public static function split(string $script): array
    {
        $order = ['FALAK_FETCH', 'FALAK_ACTIVATE', 'FALAK_RESTART_PROCS'];
        $buckets = ['FALAK_FETCH' => [], 'FALAK_ACTIVATE' => [], 'FALAK_RESTART_PROCS' => []];
        $seen = [];
        $current = [];

        foreach (preg_split('/\r?\n/', $script) ?: [] as $line) {
            if (preg_match(self::MACRO_LINE, $line, $m) === 1) {
                $macro = $m[1];

                if (isset($seen[$macro])) {
                    throw new InvalidArgumentException("\${$macro} is used more than once in the deploy script.");
                }

                foreach (array_keys($seen) as $earlier) {
                    if (array_search($earlier, $order, true) > array_search($macro, $order, true)) {
                        throw new InvalidArgumentException("\${$macro} must come before \${$earlier} in the deploy script.");
                    }
                }

                $buckets[$macro] = $current;
                $current = [];
                $seen[$macro] = true;

                continue;
            }

            $current[] = $line;
        }

        $end = $current;
        $fetch = isset($seen['FALAK_FETCH']);
        $sections = [
            'before_fetch' => $fetch ? $buckets['FALAK_FETCH'] : [],
            'before_activate' => [...($fetch ? [] : $buckets['FALAK_FETCH']), ...$buckets['FALAK_ACTIVATE']],
            'after_activate' => $buckets['FALAK_RESTART_PROCS'],
            'after_restart' => [],
        ];

        if (! isset($seen['FALAK_ACTIVATE'])) {
            $sections['before_activate'] = [...$sections['before_activate'], ...$sections['after_activate'], ...$end];
            $sections['after_activate'] = [];
        } elseif (! isset($seen['FALAK_RESTART_PROCS'])) {
            $sections['after_activate'] = [...$sections['after_activate'], ...$end];
        } else {
            $sections['after_restart'] = $end;
        }

        return array_map(fn (array $lines) => trim(implode("\n", $lines)), $sections);
    }

    /**
     * The section without blank and comment-only lines mattering: '' when nothing would run, else the text trimmed.
     */
    public static function meaningful(string $section): string
    {
        $lines = preg_split('/\r?\n/', $section) ?: [];
        $run = array_filter($lines, fn (string $line) => trim($line) !== '' && ! str_starts_with(trim($line), '#'));

        return $run === [] ? '' : trim($section);
    }

    /**
     * A script from its sections, with every macro on its own line.
     *
     * @param  array<string, string>  $sections
     */
    public static function compose(array $sections): string
    {
        $parts = [];
        $macros = ['before_fetch' => '$FALAK_FETCH', 'before_activate' => '$FALAK_ACTIVATE', 'after_activate' => '$FALAK_RESTART_PROCS', 'after_restart' => null];

        foreach ($macros as $section => $macro) {
            $text = trim($sections[$section] ?? '');

            if ($text !== '') {
                $parts[] = $text;
            }

            if ($macro !== null) {
                $parts[] = $macro;
            }
        }

        return implode("\n\n", $parts)."\n";
    }
}
