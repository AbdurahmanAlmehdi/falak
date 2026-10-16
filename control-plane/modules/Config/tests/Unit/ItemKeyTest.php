<?php

use Falak\Config\Contracts\ItemKey;

it('keeps names that are keys and maps the others to keys, without collisions', function () {
    $rows = ['Nightly report', 'Nightly-report', 'report', '  Ünïcode job!  ', '***'];

    expect(ItemKey::of('report-'))->toBe('report-')
        ->and(ItemKey::of(' Nightly  report '))->toBe('Nightly-report')
        ->and(ItemKey::of(str_repeat('a b', 40)))->toHaveLength(64)
        ->and(ItemKey::map($rows, fn (string $n) => $n))->toBe([
            'Nightly-report' => 'Nightly-report',
            'report' => 'report',
            'Nightly-report-2' => 'Nightly report',
            'Unicode-job' => '  Ünïcode job!  ',
            'item' => '***',
        ]);
});
