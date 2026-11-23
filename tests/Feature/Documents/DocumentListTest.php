<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use Tests\Support\Reviewable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->company = Company::factory()->create(['account_id' => $this->user->account_id]);
});

function documentIn(Company $company, DocumentStatus ...$path): Document
{
    $document = Document::factory()->forCompany($company)->create();
    foreach ($path as $status) {
        $document->transitionTo($status, 'Test.');
    }

    return $document;
}

it('lists every document, newest first, whatever its status', function () {
    $received = documentIn($this->company);
    $failed = documentIn($this->company, DocumentStatus::Failed);
    $validated = documentIn($this->company, DocumentStatus::Classified, DocumentStatus::Extracted, DocumentStatus::Validated);

    $this->getJson('/api/documents')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$validated->id, $failed->id, $received->id])
        ->assertJsonPath('data.1.status_label', 'Fallido');
});

it('counts documents per status for the tabs, ignoring the status filter', function () {
    documentIn($this->company);
    documentIn($this->company, DocumentStatus::Failed);
    documentIn($this->company, DocumentStatus::Failed);
    documentIn($this->company, DocumentStatus::NeedsReview);

    $this->getJson('/api/documents?status=failed')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.status_counts', [
            'received' => 1,
            'classified' => 0,
            'extracted' => 0,
            'validated' => 0,
            'exported' => 0,
            'needs_review' => 1,
            'ignored' => 0,
            'failed' => 2,
        ]);
});

it('counts only the filtered client and never other accounts', function () {
    $other = Company::factory()->create(['account_id' => $this->user->account_id]);
    documentIn($this->company, DocumentStatus::Failed);
    documentIn($other, DocumentStatus::Failed);
    documentIn(Company::factory()->create(), DocumentStatus::Failed);

    $this->getJson('/api/documents')->assertJsonPath('meta.status_counts.failed', 2);
    $this->getJson("/api/documents?company_id={$this->company->id}")->assertJsonPath('meta.status_counts.failed', 1);
});

it('searches by supplier, tax id, invoice number, file name or id', function () {
    $garcia = Reviewable::document($this->company);
    $other = Reviewable::document($this->company, [
        'supplier' => ['name' => 'Recambios Norte S.L.', 'tax_id' => 'B56789126'],
        'invoice_number' => 'RN-77',
    ]);
    $file = Document::factory()->forCompany($this->company)->create(['original_filename' => 'ticket-gasolinera.jpg']);

    $ids = fn (string $q) => collect($this->getJson('/api/documents?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('fontanería'))->toBe([$garcia->id])
        ->and($ids('B56789126'))->toBe([$other->id])
        ->and($ids('rn-77'))->toBe([$other->id])
        ->and($ids('GASOLINERA'))->toBe([$file->id])
        ->and($ids("#{$other->id}"))->toBe([$other->id])
        ->and($ids('nada que ver'))->toBe([]);
});

it('counts with the search applied, so the tabs match the list', function () {
    Reviewable::document($this->company);
    documentIn($this->company, DocumentStatus::Failed);

    $this->getJson('/api/documents?q=fontanería')
        ->assertJsonPath('meta.status_counts.needs_review', 1)
        ->assertJsonPath('meta.status_counts.failed', 0);
});

it('gives only the counts for the menu badge', function () {
    documentIn($this->company, DocumentStatus::NeedsReview);
    documentIn(Company::factory()->create(), DocumentStatus::NeedsReview);

    $this->getJson('/api/documents/counts')
        ->assertOk()
        ->assertJsonPath('data.needs_review', 1)
        ->assertJsonMissingPath('data.0');
});

it('names who made each change in the history', function () {
    $document = Reviewable::document($this->company);
    $document->transitionTo(DocumentStatus::Validated, 'Aprobada.', $this->user);

    $events = $this->getJson("/api/documents/{$document->id}")->assertOk()->json('data.events');

    expect(collect($events)->last())->user_name->toBe($this->user->name)
        ->and(collect($events)->firstWhere('user_id', null))->user_name->toBeNull();
});
