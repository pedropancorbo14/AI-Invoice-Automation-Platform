<?php

use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\FakeQrCodeReader;
use Tests\Support\Files;

beforeEach(function () {
    Storage::fake('documents');
    Carbon::setTestNow('2026-11-20 10:00:00');
    config(['invoices.extraction.enabled' => true, 'invoices.validation.enabled' => true]);

    $this->company = Company::factory()->create(['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323']);
    Supplier::factory()->known()->create(['company_id' => $this->company->id, 'tax_id' => 'B12345674']);
});

afterEach(fn () => Carbon::setTestNow());

function receiveWithQr(string $mime = 'application/pdf'): Document
{
    $company = test()->company;
    $contents = $mime === 'application/pdf' ? Files::invoicePdf() : Files::photo();

    return app(StoreDocument::class)
        ->handle($company->account, $contents, 'factura', $mime, DocumentSource::Upload, $company)
        ->refresh();
}

it('stores what the QR says next to the extraction', function () {
    $this->qr->returns(['https://tienda.example.com', FakeQrCodeReader::url()]);

    $document = receiveWithQr('image/jpeg');

    expect($document->latestExtraction->verifactu)->toBe([
        'tax_id' => 'B12345674', 'number' => 'F-2026-001', 'date' => '2026-11-15', 'total' => '121.00',
        'verifactu' => true, 'test_environment' => false,
    ])->and($this->qr->reads)->toBe(['image/jpeg']);
});

it('stores nothing when the invoice has no QR', function () {
    $document = receiveWithQr();

    expect($document->latestExtraction->verifactu)->toBeNull()
        ->and($document->status)->toBe(DocumentStatus::Validated);
});

it('validates a doubtful invoice when the QR confirms the doubtful fields', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput([
        'confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'low', 'invoice_number' => 'medium']),
    ]));
    $this->qr->returns([FakeQrCodeReader::url()]);

    expect(receiveWithQr()->status)->toBe(DocumentStatus::Validated);
});

it('sends to review an invoice that contradicts its QR, with the reason', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['total' => 112, 'tax_breakdown' => [
        ['rate' => 21, 'base' => 92.56, 'tax_amount' => 19.44, 'surcharge_rate' => null, 'surcharge_amount' => null],
    ]]));
    $this->qr->returns([FakeQrCodeReader::url()]);

    $document = receiveWithQr();

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->review_codes)->toBe(['verifactu'])
        ->and($document->status_reason)->toBe('El total no coincide con el QR de VERI*FACTU: la IA leyó 112,00 € y el QR dice 121,00 €.');
});

it('does not read QR codes when it is disabled', function () {
    config(['invoices.verifactu.enabled' => false]);
    $this->qr->returns([FakeQrCodeReader::url()]);

    expect(receiveWithQr()->latestExtraction->verifactu)->toBeNull()
        ->and($this->qr->reads)->toBe([]);
});
