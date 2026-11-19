<?php

use App\Support\Export\Ledger\Period;

it('parses months, quarters and years', function (string $value, string $key, string $from, string $to, string $label) {
    $period = Period::parse($value);

    expect($period->key)->toBe($key)
        ->and($period->from->toDateString())->toBe($from)
        ->and($period->to->toDateString())->toBe($to)
        ->and($period->label)->toBe($label);
})->with([
    ['2026-11', '2026-11', '2026-11-01', '2026-11-30', 'noviembre de 2026'],
    ['2026-02', '2026-02', '2026-02-01', '2026-02-28', 'febrero de 2026'],
    ['2026-T4', '2026-T4', '2026-10-01', '2026-12-31', '4.º trimestre de 2026'],
    ['2026-q1', '2026-T1', '2026-01-01', '2026-03-31', '1.º trimestre de 2026'],
    ['2026', '2026', '2026-01-01', '2026-12-31', 'año 2026'],
]);

it('rejects anything else', function (string $value) {
    Period::parse($value);
})->throws(InvalidArgumentException::class)->with(['2026-13', '2026-T5', 'noviembre', '']);

it('knows which dates fall inside', function () {
    $period = Period::parse('2026-T4');

    expect($period->contains('2026-10-01'))->toBeTrue()
        ->and($period->contains('2026-12-31'))->toBeTrue()
        ->and($period->contains('2026-09-30'))->toBeFalse();
});
