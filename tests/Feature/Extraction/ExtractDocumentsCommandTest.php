<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Document;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('queues the received documents', function () {
    $received = Document::factory()->count(2)->create();
    $ignored = Document::factory()->create();
    $ignored->transitionTo(DocumentStatus::Ignored);

    $this->artisan('invoices:extract --received')
        ->expectsOutput('Documentos encolados: 2.')
        ->assertSuccessful();

    Queue::assertPushed(ExtractDocument::class, 2);
});

it('retries failed documents', function () {
    $document = Document::factory()->create();
    $document->transitionTo(DocumentStatus::Failed, 'Overloaded');

    $this->artisan('invoices:extract --failed')->assertSuccessful();

    expect($document->fresh()->status)->toBe(DocumentStatus::Received);
    Queue::assertPushed(ExtractDocument::class, fn ($job) => $job->documentId === $document->id);
});

it('skips documents in other states', function () {
    $document = Document::factory()->create();
    $document->transitionTo(DocumentStatus::Ignored);

    $this->artisan('invoices:extract', ['ids' => [$document->id]])
        ->expectsOutputToContain('no se puede extraer')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('asks what to extract', function () {
    $this->artisan('invoices:extract')->assertFailed();
});
