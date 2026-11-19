<?php

use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\ValidateDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\Supplier;
use App\Support\Validation\Direction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\Files;

beforeEach(function () {
    Storage::fake('documents');
    Carbon::setTestNow('2026-11-20 10:00:00');
    config(['invoices.extraction.enabled' => true, 'invoices.validation.enabled' => true]);

    // La factura de ejemplo va de Fontanería García (B12345674) a esta empresa.
    $this->company = Company::factory()->create(['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323']);
});

afterEach(fn () => Carbon::setTestNow());

function receive(?Company $company = null, string $seed = 'a'): Document
{
    $company ??= test()->company;

    return app(StoreDocument::class)
        ->handle($company->account, Files::invoicePdf($seed), "factura-{$seed}.pdf", 'application/pdf', DocumentSource::Upload, $company)
        ->refresh();
}

function knownSupplier(): Supplier
{
    return Supplier::factory()->known()->create(['company_id' => test()->company->id, 'tax_id' => 'B12345674']);
}

it('validates on its own an invoice from a known supplier when everything adds up', function () {
    $supplier = knownSupplier();

    $document = receive();

    expect($document->status)->toBe(DocumentStatus::Validated)
        ->and($document->direction)->toBe(Direction::Received)
        ->and($document->supplier_id)->toBe($supplier->id)
        ->and($document->invoice_key)->toHaveLength(64)
        ->and($supplier->fresh()->validated_count)->toBe(2);
});

it('sends the first invoice of a new supplier to review', function () {
    $document = receive();

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toBe('Proveedor nuevo: Fontanería García S.L. (B12345674). La primera factura de cada proveedor se revisa.')
        ->and(Supplier::withoutGlobalScopes()->count())->toBe(0);
});

it('lets the next invoices of that supplier through once one was validated', function () {
    $first = receive(seed: 'a');
    $first->direction = Direction::Received;
    $first->save();
    app(App\Support\Validation\SupplierMemory::class)->remember($first, $first->latestExtraction->data);

    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['invoice_number' => 'F-2026-002']));

    expect(receive(seed: 'b')->status)->toBe(DocumentStatus::Validated);
});

it('validates issued invoices without asking about the supplier', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput([
        'supplier' => ['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323'],
        'customer' => ['name' => 'Fontanería García S.L.', 'tax_id' => 'B12345674'],
    ]));

    $document = receive();

    expect($document->status)->toBe(DocumentStatus::Validated)
        ->and($document->direction)->toBe(Direction::Issued)
        ->and($document->supplier_id)->toBeNull();
});

it('explains every failed rule and keeps the details in the audit trail', function () {
    knownSupplier();
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['total' => 130, 'issue_date' => '2026-11-25', 'due_date' => null]));

    $document = receive();
    $event = $document->events()->where('to_status', DocumentStatus::NeedsReview)->sole();

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toBe('La fecha de emisión es futura: 25/11/2026. El total no cuadra: 130,00 ≠ 100,00 + 21,00.')
        ->and($event->payload['rules'])->toBe(['dates', 'arithmetic'])
        ->and($event->payload['failures'][1])->toMatchArray(['rule' => 'arithmetic', 'field' => 'total']);
});

it('catches the same invoice arriving as a different file', function () {
    knownSupplier();
    $first = receive(seed: 'escaneo');
    $second = receive(seed: 'foto');

    expect($first->status)->toBe(DocumentStatus::Validated)
        ->and($second->status)->toBe(DocumentStatus::NeedsReview)
        ->and($second->status_reason)->toBe("Posible duplicado del documento #{$first->id} (mismo proveedor, número y total).");
});

it('does not see duplicates in other accounts', function () {
    knownSupplier();
    receive(seed: 'a');

    $otherCompany = Company::factory()->create(['tax_id' => 'B87654323']);
    Supplier::factory()->known()->create(['company_id' => $otherCompany->id, 'tax_id' => 'B12345674']);

    expect(receive($otherCompany, 'b')->status)->toBe(DocumentStatus::Validated);
});

it('ignores ignored documents when looking for duplicates', function () {
    knownSupplier();
    $first = receive(seed: 'a');
    $first->forceFill(['status' => DocumentStatus::Ignored])->saveQuietly();

    expect(receive(seed: 'b')->status)->toBe(DocumentStatus::Validated);
});

it('needs a company to validate', function () {
    $account = $this->company->account;
    $document = app(StoreDocument::class)
        ->handle($account, Files::invoicePdf(), 'f.pdf', 'application/pdf', DocumentSource::Upload)
        ->refresh();

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toContain('no tiene cliente asignado');
});

it('applies the confidence threshold of the account', function () {
    knownSupplier();
    $doubtful = FakeAnthropicApi::invoiceOutput([
        'confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'medium']),
    ]);
    $this->llm->returns($doubtful);

    expect(receive(seed: 'a')->status_reason)->toBe('Dato dudoso en total.');

    $this->company->account->update(['min_confidence' => 'medium']);
    $this->llm->returns(['invoice_number' => 'F-2026-002'] + $doubtful);

    expect(receive(seed: 'b')->status)->toBe(DocumentStatus::Validated);
});

it('does nothing with documents that are not extracted', function () {
    config(['invoices.validation.enabled' => false]);
    $document = receive();
    $document->transitionTo(DocumentStatus::NeedsReview);

    app()->call([new ValidateDocument($document->id), 'handle']);

    expect($document->fresh()->status)->toBe(DocumentStatus::NeedsReview);
});

it('leaves extracted documents alone when validation is disabled', function () {
    config(['invoices.validation.enabled' => false]);

    expect(receive()->status)->toBe(DocumentStatus::Extracted);
});
