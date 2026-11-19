<?php

use App\Actions\StoreDocument;
use App\Enums\DocumentEventType;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\Exceptions\ExtractionRefused;
use App\Support\Extraction\InputMode;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\Files;

beforeEach(function () {
    Storage::fake('documents');
    config(['invoices.extraction.enabled' => true]);

    $this->company = Company::factory()->create(['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654321']);
});

function storeFile(string $contents, string $filename, string $mime): Document
{
    $company = test()->company;

    return app(StoreDocument::class)
        ->handle($company->account, $contents, $filename, $mime, DocumentSource::Upload, $company)
        ->refresh();
}

/**
 * @return list<array{0: string|null, 1: string|null}>
 */
function transitions(Document $document): array
{
    return $document->events()
        ->where('type', DocumentEventType::StatusChanged)
        ->get()
        ->map(fn ($e) => [$e->from_status?->value, $e->to_status?->value])
        ->all();
}

it('extracts an invoice right after it is stored', function () {
    $document = storeFile(Files::invoicePdf(), 'factura.pdf', 'application/pdf');

    expect($document->status)->toBe(DocumentStatus::Extracted)
        ->and(transitions($document))->toBe([['received', 'classified'], ['classified', 'extracted']]);

    $extraction = $document->latestExtraction;

    expect($extraction->document_type->value)->toBe('invoice')
        ->and($extraction->input_mode)->toBe(InputMode::Text)
        ->and($extraction->data['total'])->toBe('121.00')
        ->and($extraction->data['supplier']['tax_id'])->toBe('B12345674')
        ->and($extraction->model)->toBe('fake-model')
        ->and($extraction->input_tokens)->toBe(1000)
        ->and((float) $extraction->cost_usd)->toBe(0.01)
        ->and($extraction->duration_ms)->toBe(1500)
        ->and($extraction->account_id)->toBe($this->company->account_id);
});

it('tells the model which company the document belongs to', function () {
    storeFile(Files::invoicePdf(), 'factura.pdf', 'application/pdf');

    expect($this->llm->inputs[0])
        ->companyName->toBe('Panadería La Espiga S.L.')
        ->companyTaxId->toBe('B87654321');
});

it('records doubtful fields in the audit trail', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput([
        'confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'low', 'issue_date' => 'medium']),
    ]));

    $document = storeFile(Files::invoicePdf(), 'factura.pdf', 'application/pdf');
    $event = $document->events()->where('to_status', DocumentStatus::Extracted)->sole();

    expect($event->payload['doubtful_fields'])->toBe(['issue_date', 'total'])
        ->and($event->payload['model'])->toBe('fake-model');
});

it('ignores documents that are not invoices', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['document_type' => 'other']));

    $document = storeFile(Files::invoicePdf(), 'contrato.pdf', 'application/pdf');

    expect($document->status)->toBe(DocumentStatus::Ignored)
        ->and($document->status_reason)->toBe('No es una factura: parece otro documento.');
});

it('sends delivery notes and quotes to review', function (string $type, string $reason) {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['document_type' => $type]));

    $document = storeFile(Files::invoicePdf(), 'doc.pdf', 'application/pdf');

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toBe($reason);
})->with([
    ['delivery_note', 'Parece un albarán, no una factura.'],
    ['quote', 'Parece un presupuesto, no una factura.'],
]);

it('keeps tickets and credit notes in the normal flow', function (string $type) {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['document_type' => $type]));

    expect(storeFile(Files::invoicePdf(), 'doc.pdf', 'application/pdf')->status)->toBe(DocumentStatus::Extracted);
})->with(['simplified_invoice', 'credit_note']);

it('sends to review when the model refuses', function () {
    $this->llm->returns(new ExtractionRefused('El modelo no ha querido procesar el documento.'));

    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toStartWith('La IA no ha podido procesarlo')
        ->and($document->extractions()->count())->toBe(0);
});

it('fails right away on a permanent error', function () {
    $this->llm->returns(new ExtractionFailed('La API de Claude rechazó la petición: clave inválida'));

    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->status_reason)->toContain('clave inválida');
});

it('lets transient errors bubble up for the queue to retry', function () {
    Queue::fake();
    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');
    $this->llm->returns(new RuntimeException('Connection reset'));

    expect(fn () => app()->call([new ExtractDocument($document->id), 'handle']))
        ->toThrow(RuntimeException::class, 'Connection reset');

    expect($document->fresh()->status)->toBe(DocumentStatus::Received)
        ->and(app(CurrentAccount::class)->has())->toBeFalse();
});

it('marks the document as failed when the retries run out', function () {
    Queue::fake();
    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');

    (new ExtractDocument($document->id))->failed(new RuntimeException('Overloaded'));

    expect($document->fresh())
        ->status->toBe(DocumentStatus::Failed)
        ->status_reason->toBe('Error técnico al extraer los datos: Overloaded');
});

it('retries with backoff and a timeout', function () {
    $job = new ExtractDocument(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300])
        ->and($job->timeout)->toBeGreaterThan((int) config('invoices.extraction.timeout'));
});

it('does nothing with a document that is no longer received', function () {
    Queue::fake();
    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');
    $document->transitionTo(DocumentStatus::Ignored);

    app()->call([new ExtractDocument($document->id), 'handle']);

    expect($this->llm->inputs)->toBeEmpty();
});

it('sends HEIC photos to review when they cannot be converted', function () {
    $document = storeFile(Files::heic(), 'IMG_0001.HEIC', 'image/heic');

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toContain('HEIC')
        ->and($this->llm->inputs)->toBeEmpty();
})->skip(fn () => (new Symfony\Component\Process\ExecutableFinder)->find('heif-convert') !== null, 'heif-convert está instalado');

it('does not queue anything when extraction is disabled', function () {
    config(['invoices.extraction.enabled' => false]);
    Queue::fake();

    storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');

    Queue::assertNothingPushed();
});

it('never sends the "invoice by link" email to the model', function () {
    $raw = Tests\Support\TestMail::make()
        ->deliveredTo($this->company->inboxAddress())
        ->html('<a href="https://portal.test/f/1">Descargar factura</a>')
        ->toString();

    app(App\Actions\ReceiveInboundEmail::class)->handle($raw);

    expect(Document::withoutGlobalScopes()->sole()->status)->toBe(DocumentStatus::NeedsReview)
        ->and($this->llm->inputs)->toBeEmpty();
});

it('keeps every extraction and exposes the latest one', function () {
    $document = storeFile(Files::invoicePdf(), 'f.pdf', 'application/pdf');
    $document->transitionTo(DocumentStatus::NeedsReview);
    $document->transitionTo(DocumentStatus::Received);
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['total' => 122]));

    app()->call([new ExtractDocument($document->id), 'handle']);

    expect(DocumentExtraction::withoutGlobalScopes()->where('document_id', $document->id)->count())->toBe(2)
        ->and($document->fresh()->latestExtraction->data['total'])->toBe('122.00');
});
