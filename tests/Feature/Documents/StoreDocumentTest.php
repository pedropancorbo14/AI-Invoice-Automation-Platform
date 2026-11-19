<?php

use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('documents'));

it('stores the file privately under the account folder', function () {
    $company = Company::factory()->create();

    $document = app(StoreDocument::class)->handle(
        $company->account,
        '%PDF-1.7 factura',
        'Factura 001.PDF',
        'application/pdf',
        DocumentSource::Upload,
        $company,
    );

    expect($document->account_id)->toBe($company->account_id)
        ->and($document->company_id)->toBe($company->id)
        ->and($document->status)->toBe(DocumentStatus::Received)
        ->and($document->path)->toStartWith("accounts/{$company->account_id}/")
        ->and($document->path)->toEndWith('.pdf')
        ->and($document->sha256)->toBe(hash('sha256', '%PDF-1.7 factura'))
        ->and($document->size)->toBe(16);

    Storage::disk('documents')->assertExists($document->path);
});

it('can store documents without a company (unassigned inbox)', function () {
    $account = Account::factory()->create();

    $document = app(StoreDocument::class)->handle($account, 'x', 'foto.jpg', 'image/jpeg', DocumentSource::Email);

    expect($document->company_id)->toBeNull();
});

it('refuses a company from another account', function () {
    $company = Company::factory()->create();
    $otherAccount = Account::factory()->create();

    expect(fn () => app(StoreDocument::class)->handle(
        $otherAccount, 'x', 'f.pdf', 'application/pdf', DocumentSource::Upload, $company,
    ))->toThrow(InvalidArgumentException::class);

    expect(Storage::disk('documents')->allFiles())->toBe([]);
});
