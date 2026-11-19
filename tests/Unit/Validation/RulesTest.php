<?php

use App\Support\Extraction\Confidence;
use App\Support\Validation\Direction;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\Rules\ArithmeticRule;
use App\Support\Validation\Rules\ConfidenceRule;
use App\Support\Validation\Rules\DatesRule;
use App\Support\Validation\Rules\DirectionRule;
use App\Support\Validation\Rules\RequiredFieldsRule;
use App\Support\Validation\Rules\TaxIdRule;
use App\Support\Validation\Rules\TaxRatesRule;
use App\Support\Validation\Validator;
use Carbon\CarbonImmutable;
use Tests\Support\Validation;

/**
 * @return list<string>
 */
function messages(string $rule, array $output = [], array $context = []): array
{
    return array_map(fn (RuleFailure $f) => $f->message, app($rule)->check(Validation::input($output, $context)));
}

it('accepts the sample invoice', function () {
    expect((new Validator)->validate(Validation::input())->failures)->toBe([]);
});

describe('aritmética', function () {
    it('accepts bases + VAT = total within ±0.02', function (float $total) {
        expect(messages(ArithmeticRule::class, ['total' => $total]))->toBe([]);
    })->with([121.00, 121.02, 120.98]);

    it('explains a total that does not add up', function () {
        expect(messages(ArithmeticRule::class, ['total' => 121.03]))
            ->toBe(['El total no cuadra: 121,03 ≠ 100,00 + 21,00.']);
    });

    it('subtracts the withholding', function () {
        $output = ['withholding' => ['rate' => 15, 'amount' => 15], 'total' => 106];

        expect(messages(ArithmeticRule::class, $output))->toBe([])
            ->and(messages(ArithmeticRule::class, ['total' => 121] + $output))
            ->toBe(['El total no cuadra: 121,00 ≠ 100,00 + 21,00 − 15,00.']);
    });

    it('accepts a withholding written as a negative amount', function () {
        expect(messages(ArithmeticRule::class, ['withholding' => ['rate' => 15, 'amount' => -15], 'total' => 106]))->toBe([]);
    });

    it('checks the withholding against its rate', function () {
        expect(messages(ArithmeticRule::class, ['withholding' => ['rate' => 15, 'amount' => 7], 'total' => 114]))
            ->toBe(['La retención no cuadra: 100,00 × 15 % = 15,00, pero pone 7,00.']);
    });

    it('adds the equivalence surcharge and several VAT rates', function () {
        expect(messages(ArithmeticRule::class, [
            'tax_breakdown' => [
                Validation::vat(21, 100, 21, ['surcharge_rate' => 5.2, 'surcharge_amount' => 5.2]),
                Validation::vat(10, 50, 5),
            ],
            'total' => 181.2,
        ]))->toBe([]);
    });

    it('works with credit notes in negative', function () {
        expect(messages(ArithmeticRule::class, [
            'document_type' => 'credit_note',
            'tax_breakdown' => [Validation::vat(21, -100, -21)],
            'total' => -121,
        ]))->toBe([]);
    });

    it('stays silent when the total or the breakdown is missing', function () {
        expect(messages(ArithmeticRule::class, ['total' => null]))->toBe([])
            ->and(messages(ArithmeticRule::class, ['tax_breakdown' => []]))->toBe([]);
    });
});

describe('tipos de IVA', function () {
    it('accepts the Spanish rates', function (float $rate, float $tax) {
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat($rate, 100, $tax)]]))->toBe([]);
    })->with([[21, 21], [10, 10], [4, 4], [0, 0]]);

    it('rejects rates that do not exist in Spain', function () {
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat(18, 100, 18)]]))
            ->toBe(['Tipo de IVA no válido en España: 18 %.']);
    });

    it('checks the VAT amount against the base', function () {
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat(21, 100, 20)]]))
            ->toBe(['El IVA al 21 % no cuadra: 100,00 × 21 % = 21,00, pero pone 20,00.']);
    });

    it('rounds like the invoices do', function () {
        // 33,33 × 21 % = 6,9993 → 7,00
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat(21, 33.33, 7.00)]]))->toBe([]);
    });

    it('checks the equivalence surcharge', function () {
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat(21, 100, 21, ['surcharge_rate' => 1.4, 'surcharge_amount' => 1.4])]]))
            ->toBe(['Recargo de equivalencia del 1,4 % no corresponde al IVA del 21 %.'])
            ->and(messages(TaxRatesRule::class, ['tax_breakdown' => [Validation::vat(21, 100, 21, ['surcharge_rate' => 5.2, 'surcharge_amount' => 4])]]))
            ->toBe(['El recargo de equivalencia del 5,2 % no cuadra: debería ser 5,20.']);
    });

    it('needs a breakdown', function () {
        expect(messages(TaxRatesRule::class, ['tax_breakdown' => []]))->toBe(['No hay desglose de IVA.']);
    });
});

describe('CIF, NIF y NIE', function () {
    it('flags an invalid supplier or customer id', function () {
        expect(messages(TaxIdRule::class, ['supplier' => ['name' => 'X', 'tax_id' => 'B12345675']]))
            ->toBe(['El CIF/NIF del proveedor no es válido: B12345675.']);
    });

    it('accepts EU VAT numbers', function () {
        expect(messages(TaxIdRule::class, ['supplier' => ['name' => 'Amazon EU', 'tax_id' => 'LU26375245']]))->toBe([]);
    });
});

describe('fechas', function () {
    it('rejects future dates', function () {
        expect(messages(DatesRule::class, ['issue_date' => '2026-11-21']))
            ->toBe(['La fecha de emisión es futura: 21/11/2026.']);
    });

    it('accepts today', function () {
        expect(messages(DatesRule::class, ['issue_date' => '2026-11-20', 'due_date' => null]))->toBe([]);
    });

    it('rejects invoices older than the configured limit', function () {
        expect(messages(DatesRule::class, ['issue_date' => '2025-11-19', 'due_date' => null]))
            ->toBe(['La fecha de emisión tiene más de 365 días: 19/11/2025.']);

        config(['invoices.validation.max_age_days' => 730]);

        expect(messages(DatesRule::class, ['issue_date' => '2025-11-19', 'due_date' => null]))->toBe([]);
    });

    it('rejects a due date before the issue date', function () {
        expect(messages(DatesRule::class, ['due_date' => '2026-11-14']))
            ->toBe(['El vencimiento (14/11/2026) es anterior a la emisión (15/11/2026).']);
    });

    it('needs an issue date', function () {
        expect(messages(DatesRule::class, ['issue_date' => null]))->toBe(['Falta la fecha de emisión.']);
    });
});

describe('campos obligatorios', function () {
    it('lists what is missing', function () {
        expect(messages(RequiredFieldsRule::class, [
            'invoice_number' => null,
            'total' => null,
            'supplier' => ['name' => 'X', 'tax_id' => null],
            'customer' => ['name' => null, 'tax_id' => null],
        ]))->toBe([
            'Falta el número de factura.',
            'Falta el total de la factura.',
            'Falta el CIF del proveedor.',
            'Falta el CIF del cliente.',
        ]);
    });

    it('does not require the customer on a ticket', function () {
        expect(messages(RequiredFieldsRule::class, ['document_type' => 'simplified_invoice', 'customer' => ['name' => null, 'tax_id' => null]]))->toBe([]);
    });

    it('sends foreign currencies to review', function () {
        expect(messages(RequiredFieldsRule::class, ['currency' => 'USD']))->toBe(['Factura en USD: revisa el cambio a euros.']);
    });
});

describe('recibida o emitida', function () {
    it('detects received and issued invoices', function () {
        expect(DirectionRule::resolve(Validation::input()))->toBe(Direction::Received)
            ->and(DirectionRule::resolve(Validation::input([], ['companyTaxId' => 'B12345674'])))->toBe(Direction::Issued);
    });

    it('treats a ticket without customer as received', function () {
        $input = Validation::input(['document_type' => 'simplified_invoice', 'customer' => ['name' => null, 'tax_id' => null]]);

        expect(DirectionRule::resolve($input))->toBe(Direction::Received);
    });

    it('flags invoices that are not from the company', function () {
        expect(messages(DirectionRule::class, [], ['companyTaxId' => 'A58818501', 'companyName' => 'Talleres S.A.']))
            ->toBe(['La factura no parece de Talleres S.A.: su CIF (A58818501) no aparece ni como cliente ni como proveedor.']);
    });

    it('needs a company to decide', function () {
        expect(messages(DirectionRule::class, [], ['companyTaxId' => null]))
            ->toBe(['El documento no tiene cliente asignado: asígnalo para validarlo.']);
    });
});

describe('confianza', function () {
    $doubtful = fn () => [
        'confidence' => array_replace(Tests\Support\FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'medium', 'issue_date' => 'low']),
        'notes' => [['field' => 'total', 'note' => 'El total está tachado']],
    ];

    it('sends doubtful key fields to review, with the reason', function () use ($doubtful) {
        expect(messages(ConfidenceRule::class, $doubtful()))->toBe([
            'Dato dudoso en fecha de emisión.',
            'Dato dudoso en total: El total está tachado.',
        ]);
    });

    it('can be relaxed per account to medium', function () use ($doubtful) {
        expect(messages(ConfidenceRule::class, $doubtful(), ['minConfidence' => Confidence::Medium]))
            ->toBe(['Dato dudoso en fecha de emisión.']);
    });

    it('ignores doubts about an optional field that is empty', function () {
        expect(messages(ConfidenceRule::class, [
            'due_date' => null,
            'confidence' => array_replace(Tests\Support\FakeAnthropicApi::invoiceOutput()['confidence'], ['due_date' => 'low']),
        ]))->toBe([]);
    });
});

it('collects every failure with a readable reason', function () {
    $report = (new Validator)->validate(Validation::input(['total' => 130, 'issue_date' => '2027-01-01', 'due_date' => null]));

    expect($report->passed())->toBeFalse()
        ->and($report->rules())->toBe(['dates', 'arithmetic'])
        ->and($report->reason())->toBe('La fecha de emisión es futura: 01/01/2027. El total no cuadra: 130,00 ≠ 100,00 + 21,00.')
        ->and($report->direction)->toBe(Direction::Received);
});

it('uses today in Madrid', function () {
    expect(CarbonImmutable::parse('2026-11-20', 'Europe/Madrid')->timezoneName)->toBe('Europe/Madrid');
});
