<?php

use App\Support\Evaluation\FieldComparator;
use App\Support\Extraction\ExtractionSchema;
use Tests\Support\FakeAnthropicApi;

function extracted(array $overrides = []): array
{
    return ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput($overrides))['data'];
}

it('scores every annotated field', function () {
    $expected = [
        'document_type' => 'invoice',
        'supplier' => ['name' => 'Fontaneria Garcia, SL', 'tax_id' => 'ESB12345674'],
        'customer' => ['tax_id' => 'B-87654323'],
        'invoice_number' => 'F 2026 001',
        'issue_date' => '2026-11-15',
        'due_date' => '2026-12-15',
        'tax_breakdown' => [['rate' => 21, 'base' => 100, 'tax_amount' => 21]],
        'withholding' => ['amount' => null],
        'total' => 121.01,
    ];

    expect((new FieldComparator)->compare($expected, extracted()))->toBe(array_fill_keys(FieldComparator::FIELDS, true));
});

it('only scores the fields present in the annotation', function () {
    expect((new FieldComparator)->compare(['total' => 121], extracted()))->toBe(['total' => true]);
});

it('catches wrong values', function () {
    $results = (new FieldComparator)->compare([
        'supplier' => ['name' => 'Electricidad Pérez S.L.', 'tax_id' => 'B99999999'],
        'issue_date' => '2026-11-16',
        'tax_breakdown' => [['rate' => 10, 'base' => 100, 'tax_amount' => 10]],
        'total' => 121.02,
    ], extracted());

    expect($results)->toBe([
        'supplier_name' => false,
        'supplier_tax_id' => false,
        'issue_date' => false,
        'base' => true,
        'tax' => false,
        'tax_breakdown' => false,
        'total' => false,
    ]);
});

it('compares the breakdown regardless of order', function () {
    $actual = extracted(['tax_breakdown' => [
        ['rate' => 10, 'base' => 50, 'tax_amount' => 5, 'surcharge_rate' => null, 'surcharge_amount' => null],
        ['rate' => 21, 'base' => 100, 'tax_amount' => 21, 'surcharge_rate' => null, 'surcharge_amount' => null],
    ]]);

    $results = (new FieldComparator)->compare(['tax_breakdown' => [
        ['rate' => 21, 'base' => 100, 'tax_amount' => 21],
        ['rate' => 10, 'base' => 50, 'tax_amount' => 5],
    ]], $actual);

    expect($results)->toBe(['base' => true, 'tax' => true, 'tax_breakdown' => true]);
});

it('treats a missing withholding as zero', function () {
    expect((new FieldComparator)->compare(['withholding' => ['amount' => 0]], extracted()))->toBe(['withholding' => true])
        ->and((new FieldComparator)->compare(['withholding' => ['amount' => 15]], extracted()))->toBe(['withholding' => false]);
});
