<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;

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
