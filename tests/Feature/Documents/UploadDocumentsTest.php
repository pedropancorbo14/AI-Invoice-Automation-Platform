<?php

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

beforeEach(fn () => Storage::fake('documents'));

function upload(string $content, string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('uploads invoices for a company', function () {
    $user = actingAsUser();
    $company = Company::factory()->create(['account_id' => $user->account_id]);

    $this->postJson('/api/documents', [
        'company_id' => $company->id,
        'files' => [upload(Files::pdf('a'), 'a.pdf'), upload(Files::photo(), 'foto.jpg')],
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.created')
        ->assertJsonPath('data.created.0.source', 'upload')
        ->assertJsonPath('data.created.0.company_id', $company->id)
        ->assertJsonPath('data.created.1.mime_type', 'image/jpeg');

    expect(Document::withoutGlobalScopes()->where('source', DocumentSource::Upload)->count())->toBe(2)
        ->and(Document::withoutGlobalScopes()->first()->status)->toBe(DocumentStatus::Received);
});

it('leaves uploads without a company in the unassigned inbox', function () {
    actingAsUser();

    $this->postJson('/api/documents', ['files' => [upload(Files::pdf(), 'f.pdf')]])
        ->assertCreated()
        ->assertJsonPath('data.created.0.company_id', null);
});

it('reports duplicates and rejected files instead of failing', function () {
    $user = actingAsUser();
    $this->postJson('/api/documents', ['files' => [upload(Files::pdf('ya'), 'primera.pdf')]])->assertCreated();

    $this->postJson('/api/documents', ['files' => [
        upload(Files::pdf('ya'), 'otra-vez.pdf'),
        upload('hola', 'notas.txt'),
        upload(Files::pdf('nuevo'), 'nueva.pdf'),
    ]])
        ->assertCreated()
        ->assertJsonCount(1, 'data.created')
        ->assertJsonPath('data.duplicates.0.filename', 'otra-vez.pdf')
        ->assertJsonPath('data.rejected.0.filename', 'notas.txt');

    expect(Document::withoutGlobalScopes()->where('account_id', $user->account_id)->count())->toBe(2);
});

it('answers 200 when nothing new was created', function () {
    actingAsUser();

    $this->postJson('/api/documents', ['files' => [upload('hola', 'notas.txt')]])
        ->assertOk()
        ->assertJsonCount(0, 'data.created');
});

it('does not accept a company from another account', function () {
    actingAsUser();
    $foreign = Company::factory()->for(Account::factory())->create();

    $this->postJson('/api/documents', ['company_id' => $foreign->id, 'files' => [upload(Files::pdf(), 'f.pdf')]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_id');
});

it('requires at least one file', function () {
    actingAsUser();

    $this->postJson('/api/documents', [])->assertUnprocessable()->assertJsonValidationErrors('files');
});

it('assigns an unassigned document to a company', function () {
    $user = actingAsUser();
    $company = Company::factory()->create(['account_id' => $user->account_id]);
    $document = Document::factory()->create(['account_id' => $user->account_id]);

    $this->putJson("/api/documents/{$document->id}/company", ['company_id' => $company->id])
        ->assertOk()
        ->assertJsonPath('data.company_id', $company->id)
        ->assertJsonPath('data.events.1.type', 'assigned');

    expect($document->events()->latest('id')->first()->user_id)->toBe($user->id);
});

it('cannot assign to a company from another account', function () {
    $user = actingAsUser();
    $foreign = Company::factory()->create();
    $document = Document::factory()->create(['account_id' => $user->account_id]);

    $this->putJson("/api/documents/{$document->id}/company", ['company_id' => $foreign->id])
        ->assertUnprocessable();
});

it('filters the unassigned inbox', function () {
    $user = actingAsUser();
    $company = Company::factory()->create(['account_id' => $user->account_id]);
    Document::factory()->forCompany($company)->create();
    $loose = Document::factory()->create(['account_id' => $user->account_id]);

    $this->getJson('/api/documents?unassigned=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $loose->id);
});
