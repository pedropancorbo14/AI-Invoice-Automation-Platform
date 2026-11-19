<?php

use App\Support\Extraction\Confidence;
use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\TaxId;
use Tests\Support\FakeAnthropicApi;

it('normalizes amounts to two-decimal strings', function (mixed $value, ?string $expected) {
    expect(ExtractionSchema::amount($value))->toBe($expected);
})->with([
    [121, '121.00'],
    [121.005, '121.01'],
    [-50.5, '-50.50'],
    ['33.3', '33.30'],
    [0, '0.00'],
    [null, null],
    ['1.234,56', null],
]);

it('normalizes tax ids', function (?string $raw, ?string $expected) {
    expect(TaxId::normalize($raw))->toBe($expected);
})->with([
    ['B-12345674', 'B12345674'],
    ['b 12.345.674', 'B12345674'],
    ['ESB12345674', 'B12345674'],
    ['12345678Z', '12345678Z'],
    ['DE123456789', 'DE123456789'],
    ['', null],
    [null, null],
]);

it('drops impossible dates and lowers their confidence', function () {
    $result = ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput(['issue_date' => '2026-02-30']));

    expect($result['data']['issue_date'])->toBeNull()
        ->and($result['confidence']['issue_date'])->toBe(Confidence::Low)
        ->and($result['notes'][0]['field'])->toBe('issue_date');
});

it('treats missing confidence as low', function () {
    $output = FakeAnthropicApi::invoiceOutput();
    unset($output['confidence']['total']);

    expect(ExtractionSchema::normalize($output)['confidence']['total'])->toBe(Confidence::Low);
});

it('rejects an unknown document type', function () {
    ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput(['document_type' => 'nómina']));
})->throws(ExtractionFailed::class);

it('builds a schema where every object is closed and fully required', function () {
    $check = function (array $node) use (&$check): void {
        if (($node['type'] ?? null) === 'object') {
            expect($node['additionalProperties'])->toBeFalse()
                ->and($node['required'])->toBe(array_keys($node['properties']));
            array_map($check, $node['properties']);
        }
        if (isset($node['items'])) {
            $check($node['items']);
        }
        foreach ($node['anyOf'] ?? [] as $option) {
            $check($option);
        }
    };

    $check(ExtractionSchema::jsonSchema());
});
