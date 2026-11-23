<?php

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\Supplier;
use App\Support\Extraction\InputMode;
use App\Support\Validation\Direction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;
use Tests\Support\Reviewable;

beforeEach(function () {
    Storage::fake('documents');
    Carbon::setTestNow('2026-11-20 10:00:00');

    $this->user = actingAsUser();
    $this->company = Company::factory()->create(['account_id' => $this->user->account_id, 'tax_id' => 'B87654323']);
});

afterEach(fn () => Carbon::setTestNow());

describe('aprobar', function () {
    it('approves as it is and remembers the supplier', function () {
        $document = Reviewable::document($this->company);

        $this->postJson("/api/documents/{$document->id}/approve", [
            'data' => Reviewable::formData(),
            'supplier' => ['ledger_account' => '6280001', 'category' => 'Suministros'],
        ])->assertOk()->assertJsonPath('data.status', 'validated');

        $document->refresh();
        $supplier = Supplier::withoutGlobalScopes()->sole();

        expect($document->direction)->toBe(Direction::Received)
            ->and($document->review_codes)->toBeNull()
            ->and($document->supplier_id)->toBe($supplier->id)
            ->and($document->latestExtraction->input_mode)->toBe(InputMode::Manual)
            ->and($document->events()->where('type', DocumentEventType::Corrected)->count())->toBe(0)
            ->and($document->events()->latest('id')->first()->user_id)->toBe($this->user->id)
            ->and($supplier)->ledger_account->toBe('6280001')->category->toBe('Suministros')->validated_count->toBe(1);
    });

    it('records every corrected field with before, after and who', function () {
        $document = Reviewable::document($this->company, ['total' => 130, 'invoice_number' => 'F-2026-0O1']);

        $this->postJson("/api/documents/{$document->id}/approve", [
            'data' => Reviewable::formData(['total' => '121.00', 'invoice_number' => 'F-2026-001']),
        ])->assertOk();

        $corrections = $document->events()->where('type', DocumentEventType::Corrected)->get();

        expect($corrections->pluck('payload')->all())->toBe([
            ['field' => 'invoice_number', 'before' => 'F-2026-0O1', 'after' => 'F-2026-001'],
            ['field' => 'total', 'before' => '130.00', 'after' => '121.00'],
        ])->and($corrections->pluck('user_id')->unique()->all())->toBe([$this->user->id])
            ->and($document->fresh()->latestExtraction->data['total'])->toBe('121.00');
    });

    it('warns when the data still does not add up, and approves only if confirmed', function () {
        $document = Reviewable::document($this->company);
        $data = Reviewable::formData(['total' => '130.00']);

        $this->postJson("/api/documents/{$document->id}/approve", ['data' => $data])
            ->assertUnprocessable()
            ->assertJsonPath('needs_confirmation', true)
            ->assertJsonPath('warnings.0.message', 'El total no cuadra: 130,00 ≠ 100,00 + 21,00.');

        expect($document->fresh()->status)->toBe(DocumentStatus::NeedsReview);

        $this->postJson("/api/documents/{$document->id}/approve", ['data' => $data, 'force' => true])->assertOk();

        $event = $document->events()->where('to_status', DocumentStatus::Validated)->sole();

        expect($event->payload['forced'])->toBeTrue()
            ->and($event->payload['warnings'])->toBe(['El total no cuadra: 130,00 ≠ 100,00 + 21,00.']);
    });

    it('does not bother about confidence or new suppliers when a person approves', function () {
        $document = Reviewable::document($this->company, codes: ['new_supplier', 'confidence']);

        $this->postJson("/api/documents/{$document->id}/approve", ['data' => Reviewable::formData()])->assertOk();
    });

    it('needs a company to approve', function () {
        $document = Reviewable::document($this->company);
        $document->forceFill(['company_id' => null])->save();

        $this->postJson("/api/documents/{$document->id}/approve", ['data' => Reviewable::formData()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Asigna el documento a un cliente antes de aprobarlo.');
    });

    it('only approves documents waiting for review', function () {
        $document = Reviewable::document($this->company);
        $document->transitionTo(DocumentStatus::Ignored);

        $this->postJson("/api/documents/{$document->id}/approve", ['data' => Reviewable::formData()])->assertUnprocessable();
    });

    it('cannot touch documents from another account', function () {
        $foreign = Reviewable::document(Company::factory()->create());

        $this->postJson("/api/documents/{$foreign->id}/approve", ['data' => Reviewable::formData()])->assertNotFound();
        $this->postJson("/api/documents/{$foreign->id}/not-invoice")->assertNotFound();
    });
});

describe('descartar', function () {
    it('marks a document as not an invoice', function () {
        $document = Reviewable::document($this->company, codes: ['not_invoice']);

        $this->postJson("/api/documents/{$document->id}/not-invoice")
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.status_reason', "No es una factura (lo indicó {$this->user->name}).");
    });

    it('marks a document as a duplicate of another one', function () {
        $original = Document::factory()->forCompany($this->company)->create();
        $document = Reviewable::document($this->company, codes: ['duplicate']);

        $this->postJson("/api/documents/{$document->id}/duplicate", ['of_document_id' => $original->id])
            ->assertOk()
            ->assertJsonPath('data.status_reason', "Duplicado del documento #{$original->id}.");
    });

    it('does not accept an original from another account', function () {
        $foreign = Document::factory()->create();
        $document = Reviewable::document($this->company);

        $this->postJson("/api/documents/{$document->id}/duplicate", ['of_document_id' => $foreign->id])->assertNotFound();
    });
});

describe('adjuntar el fichero que faltaba', function () {
    it('replaces the file and sends the document back to extraction', function () {
        Queue::fake();
        config(['invoices.extraction.enabled' => true]);
        $document = Reviewable::document($this->company, codes: ['link_invoice'], reason: 'Factura por enlace');

        $this->post("/api/documents/{$document->id}/file", [
            'file' => UploadedFile::fake()->createWithContent('factura-noviembre.pdf', Files::invoicePdf()),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'received');

        $document->refresh();

        expect($document->original_filename)->toBe('factura-noviembre.pdf')
            ->and($document->mime_type)->toBe('application/pdf')
            ->and($document->sha256)->toBe(hash('sha256', Files::invoicePdf()))
            ->and($document->events()->where('type', DocumentEventType::FileReplaced)->sole()->user_id)->toBe($this->user->id);

        Storage::disk('documents')->assertExists($document->path);
        Queue::assertPushed(ExtractDocument::class, fn ($job) => $job->documentId === $document->id);
    });

    it('rejects files that are not invoices or already exist', function () {
        $existing = Document::factory()->forCompany($this->company)->create(['sha256' => hash('sha256', Files::invoicePdf())]);
        $document = Reviewable::document($this->company, codes: ['link_invoice']);

        $this->post("/api/documents/{$document->id}/file", [
            'file' => UploadedFile::fake()->createWithContent('notas.txt', 'hola'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->post("/api/documents/{$document->id}/file", [
            'file' => UploadedFile::fake()->createWithContent('f.pdf', Files::invoicePdf()),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Ese fichero ya está en el documento #{$existing->id}.");
    });
});

describe('bandeja', function () {
    it('lists pending documents oldest first, with their reasons', function () {
        $first = Reviewable::document($this->company, codes: ['arithmetic', 'dates']);
        $second = Reviewable::document($this->company, codes: ['new_supplier']);

        $this->getJson('/api/documents?status=needs_review')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.review_reasons.0', ['code' => 'arithmetic', 'label' => 'El total no cuadra'])
            ->assertJsonPath('data.0.company.name', $this->company->name)
            ->assertJsonPath('data.0.extraction.data.total', '121.00')
            ->assertJsonPath('data.1.id', $second->id);
    });

    it('filters by reason', function () {
        Reviewable::document($this->company, codes: ['arithmetic']);
        $supplier = Reviewable::document($this->company, codes: ['new_supplier']);

        $this->getJson('/api/documents?status=needs_review&review_code=new_supplier')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supplier->id);
    });

    it('serves the file inline for the viewer, or as a download', function () {
        $document = Reviewable::document($this->company);
        Storage::disk('documents')->put($document->path, Files::pdf());

        expect($this->get("/api/documents/{$document->id}/file")->headers->get('content-disposition'))->toStartWith('inline')
            ->and($this->get("/api/documents/{$document->id}/file?download=1")->headers->get('content-disposition'))->toStartWith('attachment');
    });
});

it('clears the review reasons once the document leaves review', function () {
    $document = Reviewable::document($this->company, codes: ['link_invoice']);
    $document->transitionTo(DocumentStatus::Received);

    expect($document->fresh()->review_codes)->toBeNull();
});

describe('volver a procesar', function () {
    it('sends an ignored or failed document back to the AI', function (DocumentStatus $status) {
        Queue::fake();
        config(['invoices.extraction.enabled' => true]);
        $document = Document::factory()->forCompany($this->company)->create();
        $document->transitionTo($status, 'Test.');

        $this->postJson("/api/documents/{$document->id}/reprocess")
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $last = $document->events()->reorder('id', 'desc')->first();
        expect($last->user_id)->toBe($this->user->id)
            ->and($last->reason)->toContain($this->user->name);
        Queue::assertPushed(ExtractDocument::class, fn ($job) => $job->documentId === $document->id);
    })->with([
        'ignorado' => [DocumentStatus::Ignored],
        'fallido' => [DocumentStatus::Failed],
    ]);

    it('refuses documents that are not ignored or failed', function () {
        $document = Reviewable::document($this->company);

        $this->postJson("/api/documents/{$document->id}/reprocess")->assertUnprocessable();
        expect($document->refresh()->status)->toBe(DocumentStatus::NeedsReview);
    });

    it('refuses an email without the invoice: there is nothing to read again', function () {
        $document = Document::factory()->forCompany($this->company)->create(['mime_type' => 'message/rfc822']);
        $document->transitionTo(DocumentStatus::Ignored, 'Test.');

        $this->postJson("/api/documents/{$document->id}/reprocess")->assertUnprocessable();
    });

    it('cannot touch documents from another account', function () {
        $foreign = Document::factory()->create();
        $foreign->transitionTo(DocumentStatus::Failed, 'Test.');

        $this->postJson("/api/documents/{$foreign->id}/reprocess")->assertNotFound();
    });
});
