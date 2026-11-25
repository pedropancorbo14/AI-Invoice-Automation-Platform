<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Support\Validation\Direction;
use Tests\Support\Reviewable;

/**
 * Factura ya validada (o exportada) con su dirección.
 *
 * @param  array<string, mixed>  $output
 */
function validatedInvoice(Company $company, Direction $direction, array $output, DocumentStatus $status = DocumentStatus::Validated): Document
{
    $document = Reviewable::document($company, $output);
    $document->direction = $direction;
    $document->save();
    $document->transitionTo(DocumentStatus::Validated);

    if ($status === DocumentStatus::Exported) {
        $document->transitionTo(DocumentStatus::Exported);
    }

    return $document;
}

/**
 * @return list<array<string, mixed>>
 */
function vatLine(float $rate, float $base, float $tax, ?float $surchargeRate = null, ?float $surcharge = null): array
{
    return [['rate' => $rate, 'base' => $base, 'tax_amount' => $tax, 'surcharge_rate' => $surchargeRate, 'surcharge_amount' => $surcharge]];
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->company = Company::factory()->create(['account_id' => $this->user->account_id, 'name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323']);
});

it('adds up output and input VAT of the quarter by rate, in cents', function () {
    $c = $this->company;

    // Emitidas: dos tipos (uno con recargo), una rectificativa y una con IRPF.
    validatedInvoice($c, Direction::Issued, [
        'issue_date' => '2026-10-02',
        'tax_breakdown' => [...vatLine(21, 1000, 210), ...vatLine(10, 200, 20, 1.4, 2.8)],
        'total' => 1232.8,
    ]);
    validatedInvoice($c, Direction::Issued, [
        'document_type' => 'credit_note', 'issue_date' => '2026-11-20',
        'tax_breakdown' => vatLine(21, -100, -21), 'total' => -121,
    ], DocumentStatus::Exported);
    validatedInvoice($c, Direction::Issued, [
        'issue_date' => '2026-12-31',
        'tax_breakdown' => vatLine(21, 500, 105), 'withholding' => ['rate' => 15, 'amount' => 75], 'total' => 530,
    ]);

    // Recibidas: un profesional con IRPF, un ticket y una con recargo.
    validatedInvoice($c, Direction::Received, ['withholding' => ['rate' => 15, 'amount' => 15], 'total' => 106]);
    validatedInvoice($c, Direction::Received, ['document_type' => 'simplified_invoice', 'tax_breakdown' => vatLine(10, 30, 3), 'total' => 33]);
    validatedInvoice($c, Direction::Received, ['tax_breakdown' => vatLine(4, 50, 2, 0.5, 0.25), 'total' => 52.25]);

    // No cuentan: en dólares y de otro trimestre.
    validatedInvoice($c, Direction::Received, ['currency' => 'USD']);
    validatedInvoice($c, Direction::Received, ['issue_date' => '2026-09-30']);

    $data = $this->getJson("/api/companies/{$c->id}/vat-summary?period=2026-T4")->assertOk()->json('data');

    expect($data['period'])->toBe(['key' => '2026-T4', 'label' => '4.º trimestre de 2026', 'from' => '2026-10-01', 'to' => '2026-12-31'])
        ->and($data['issued'])->toBe([
            'documents' => 3,
            'base' => '1600.00',
            'tax' => '314.00',
            'surcharge' => '2.80',
            'rates' => [
                ['rate' => 21, 'base' => '1400.00', 'tax' => '294.00', 'surcharge' => '0.00'],
                ['rate' => 10, 'base' => '200.00', 'tax' => '20.00', 'surcharge' => '2.80'],
            ],
        ])
        ->and($data['received'])->toBe([
            'documents' => 3,
            'base' => '180.00',
            'tax' => '26.00',
            'surcharge' => '0.25',
            'rates' => [
                ['rate' => 21, 'base' => '100.00', 'tax' => '21.00', 'surcharge' => '0.00'],
                ['rate' => 10, 'base' => '30.00', 'tax' => '3.00', 'surcharge' => '0.00'],
                ['rate' => 4, 'base' => '50.00', 'tax' => '2.00', 'surcharge' => '0.25'],
            ],
            'tickets' => 1,
        ])
        ->and($data['withholdings'])->toBe([
            'issued' => ['documents' => 1, 'base' => '500.00', 'amount' => '75.00'],
            'received' => ['documents' => 1, 'base' => '100.00', 'amount' => '15.00'],
        ])
        // 314 + 2,80 de recargo repercutido − 26 deducible (el recargo soportado no se deduce).
        ->and($data['result'])->toBe(['amount' => '290.80', 'kind' => 'to_pay'])
        ->and($data['warnings']['other_currency'])->toBe(1);
});

it('reports a negative result as an amount to offset', function () {
    validatedInvoice($this->company, Direction::Issued, ['tax_breakdown' => vatLine(21, 100, 21)]);
    validatedInvoice($this->company, Direction::Received, ['tax_breakdown' => vatLine(21, 1000, 210), 'total' => 1210]);

    $this->getJson("/api/companies/{$this->company->id}/vat-summary?period=2026-11")
        ->assertOk()
        ->assertJsonPath('data.result', ['amount' => '-189.00', 'kind' => 'to_offset']);
});

it('sums many small amounts without floating point drift', function () {
    $lines = array_merge(...array_fill(0, 10, vatLine(21, 0.1, 0.02)));
    validatedInvoice($this->company, Direction::Received, ['tax_breakdown' => $lines, 'total' => 1.2]);

    $this->getJson("/api/companies/{$this->company->id}/vat-summary?period=2026-11")
        ->assertJsonPath('data.received.base', '1.00')
        ->assertJsonPath('data.received.tax', '0.20');
});

it('is empty when nothing was validated in the period', function () {
    $this->getJson("/api/companies/{$this->company->id}/vat-summary?period=2026")
        ->assertOk()
        ->assertJsonPath('data.issued.documents', 0)
        ->assertJsonPath('data.issued.rates', [])
        ->assertJsonPath('data.result', ['amount' => '0.00', 'kind' => 'zero']);
});

it('warns about documents of the period that are not validated yet', function () {
    Reviewable::document($this->company); // en revisión, 15/11
    Reviewable::document($this->company, ['issue_date' => '2026-07-01']); // de otro trimestre
    Document::factory()->forCompany($this->company)->create(['created_at' => '2026-10-05 10:00:00']); // sin leer aún

    $ignored = Reviewable::document($this->company);
    $ignored->transitionTo(DocumentStatus::Ignored);

    $this->getJson("/api/companies/{$this->company->id}/vat-summary?period=2026-T4")
        ->assertJsonPath('data.warnings.pending', 2)
        ->assertJsonPath('data.issued.documents', 0);
});

it('validates the period', function () {
    $this->getJson("/api/companies/{$this->company->id}/vat-summary")->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->getJson("/api/companies/{$this->company->id}/vat-summary?period=2026-T5")->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('cannot see the summary of a company from another account', function () {
    $foreign = Company::factory()->create();

    $this->getJson("/api/companies/{$foreign->id}/vat-summary?period=2026-T4")->assertNotFound();
});
